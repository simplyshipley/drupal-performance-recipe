// Self-hosted Redis for one Drupal site on Azure Container Apps.
// Ruling (Bo, 2026-09-25): self-hosted Redis container, not the managed
// service. NOT DEPLOYED: everything here is [ASSUMED] until run in a
// non-production subscription. Validated only with `az bicep build`.
//
// - Internal-only Container Apps environment in an existing VNet subnet
//   (delegated to Microsoft.App/environments, /23 or larger for the
//   Consumption workload profile [ASSUMED]).
// - Exactly 1 replica: Drupal needs one shared Redis; more replicas would be
//   separate, unsynchronised caches.
// - No persistence (cache only), explicit maxmemory, allkeys-lru.
// - Password from a Container Apps secret (pass it in from Key Vault).
// - TCP liveness/readiness probes; the platform restarts the replica.

targetScope = 'resourceGroup'

@description('Name prefix, e.g. oig-cpsc-prod.')
param namePrefix string

@description('Region. Azure Government regions: usgovvirginia, usgovarizona, usgovtexas.')
param location string = resourceGroup().location

@description('Resource ID of the subnet for the Container Apps environment (delegated to Microsoft.App/environments).')
param infrastructureSubnetId string

@description('Log Analytics workspace resource ID for environment logs.')
param logAnalyticsWorkspaceId string

@description('Redis password (requirepass). Pass from Key Vault; never commit it.')
@secure()
param redisPassword string

@description('Container image, pinned by version. Swap for a hardened image (e.g. Iron Bank redis) if available.')
param redisImage string = 'docker.io/library/redis:7.4.11'

@description('Redis maxmemory. Keep about 25 percent below the container memory.')
param maxmemory string = '768mb'

@description('Container CPU cores.')
param cpu string = '0.5'

@description('Container memory (must be a valid Container Apps CPU/memory pair).')
param memory string = '1Gi'

@description('Action group resource ID for alerts (optional).')
param actionGroupId string = ''

var workspace = split(logAnalyticsWorkspaceId, '/')

resource law 'Microsoft.OperationalInsights/workspaces@2023-09-01' existing = {
  name: last(workspace)
  scope: resourceGroup(workspace[2], workspace[4])
}

resource env 'Microsoft.App/managedEnvironments@2024-03-01' = {
  name: '${namePrefix}-cae'
  location: location
  properties: {
    vnetConfiguration: {
      infrastructureSubnetId: infrastructureSubnetId
      internal: true
    }
    appLogsConfiguration: {
      destination: 'log-analytics'
      logAnalyticsConfiguration: {
        customerId: law.properties.customerId
        sharedKey: law.listKeys().primarySharedKey
      }
    }
    workloadProfiles: [
      {
        name: 'Consumption'
        workloadProfileType: 'Consumption'
      }
    ]
  }
}

resource redis 'Microsoft.App/containerApps@2024-03-01' = {
  name: '${namePrefix}-redis'
  location: location
  properties: {
    managedEnvironmentId: env.id
    workloadProfileName: 'Consumption'
    configuration: {
      activeRevisionsMode: 'Single'
      secrets: [
        {
          name: 'redis-password'
          value: redisPassword
        }
      ]
      ingress: {
        external: false
        transport: 'tcp'
        targetPort: 6379
        exposedPort: 6379
      }
    }
    template: {
      containers: [
        {
          name: 'redis'
          image: redisImage
          resources: {
            cpu: json(cpu)
            memory: memory
          }
          env: [
            {
              name: 'REDIS_PASSWORD'
              secretRef: 'redis-password'
            }
          ]
          // sh -c so the password comes from the environment, not the
          // command line stored in the resource definition.
          command: [
            'sh'
            '-c'
          ]
          args: [
            'exec redis-server --requirepass "$REDIS_PASSWORD" --maxmemory ${maxmemory} --maxmemory-policy allkeys-lru --appendonly no --save "" --protected-mode no'
          ]
          probes: [
            {
              type: 'Liveness'
              tcpSocket: {
                port: 6379
              }
              initialDelaySeconds: 5
              periodSeconds: 10
              failureThreshold: 3
            }
            {
              type: 'Readiness'
              tcpSocket: {
                port: 6379
              }
              periodSeconds: 5
              failureThreshold: 3
            }
          ]
        }
      ]
      scale: {
        minReplicas: 1
        maxReplicas: 1
      }
    }
  }
}

// Alerts (Container Apps platform metrics). Metric names [ASSUMED]; check
// `az monitor metrics list-definitions --resource <redis app id>`.
resource restartAlert 'Microsoft.Insights/metricAlerts@2018-03-01' = if (!empty(actionGroupId)) {
  name: '${namePrefix}-redis-restarts'
  location: 'global'
  properties: {
    description: 'Redis container restarted (cache emptied; Drupal serves misses until it refills).'
    severity: 2
    enabled: true
    scopes: [
      redis.id
    ]
    evaluationFrequency: 'PT5M'
    windowSize: 'PT15M'
    criteria: {
      'odata.type': 'Microsoft.Azure.Monitor.SingleResourceMultipleMetricCriteria'
      allOf: [
        {
          name: 'restarts'
          metricName: 'RestartCount'
          operator: 'GreaterThan'
          threshold: 0
          timeAggregation: 'Total'
          criterionType: 'StaticThresholdCriterion'
        }
      ]
    }
    actions: [
      {
        actionGroupId: actionGroupId
      }
    ]
  }
}

resource memoryAlert 'Microsoft.Insights/metricAlerts@2018-03-01' = if (!empty(actionGroupId)) {
  name: '${namePrefix}-redis-memory'
  location: 'global'
  properties: {
    description: 'Redis container working set above 90 percent of its memory.'
    severity: 3
    enabled: true
    scopes: [
      redis.id
    ]
    evaluationFrequency: 'PT5M'
    windowSize: 'PT15M'
    criteria: {
      'odata.type': 'Microsoft.Azure.Monitor.SingleResourceMultipleMetricCriteria'
      allOf: [
        {
          name: 'memory'
          metricName: 'WorkingSetBytes'
          operator: 'GreaterThan'
          threshold: 966367641
          timeAggregation: 'Average'
          criterionType: 'StaticThresholdCriterion'
        }
      ]
    }
    actions: [
      {
        actionGroupId: actionGroupId
      }
    ]
  }
}

@description('Host name for REDIS_HOST on the App Service (reachable inside the VNet).')
output redisHost string = redis.properties.configuration.ingress.fqdn

@description('Environment default domain (needs a private DNS zone linked to the App Service VNet).')
output environmentDefaultDomain string = env.properties.defaultDomain

@description('Environment static IP (A record target for the private DNS zone).')
output environmentStaticIp string = env.properties.staticIp
