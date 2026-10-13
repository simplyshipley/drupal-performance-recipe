// Self-hosted Redis for one Drupal site on Azure Container Apps.
// Ruling (Bo, 2026-09-25): self-hosted Redis container, not the managed
// service. Not deployed yet: compiles with `az bicep build`; settings cite
// Microsoft Learn in azure/README.md. Confirm on qa: docs/SETUP-GUIDE.md section 3.
//
// - Internal-only Container Apps environment in an existing VNet subnet
//   (delegated to Microsoft.App/environments; /27 minimum for this
//   workload profiles environment per Microsoft Learn; size for growth).
// - Exactly 1 replica: Drupal needs one shared Redis; more replicas would be
//   separate, unsynchronised caches.
// - No persistence (cache only), explicit maxmemory, volatile-lfu (the
//   image default; redis module README, "Eviction policy").
// - Password from a Container Apps secret (pass it in from Key Vault).
// - Image: image/ (non-root, config rendered from env, dangerous
//   commands denied by ACL), pulled from ACR with a managed identity.
// - TCP liveness/readiness probes; the platform restarts the replica.

targetScope = 'resourceGroup'

@description('Name prefix, e.g. <site>-prod.')
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

@description('Container image built from image/ (docs/redis-image.md), pinned by tag. Placeholder registry name.')
param redisImage string = '<registry-name>.azurecr.us/drupal/redis:9.1.2-1'

@description('ACR login server to pull from, e.g. <registry-name>.azurecr.us. Empty = public image, no registry auth.')
param acrServer string = ''

@description('Resource ID of a user-assigned managed identity with AcrPull on the registry (required when acrServer is set).')
param acrPullIdentityId string = ''

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
  identity: empty(acrPullIdentityId) ? { type: 'None' } : {
    type: 'UserAssigned'
    userAssignedIdentities: {
      '${acrPullIdentityId}': {}
    }
  }
  properties: {
    managedEnvironmentId: env.id
    workloadProfileName: 'Consumption'
    configuration: {
      activeRevisionsMode: 'Single'
      registries: empty(acrServer) ? [] : [
        {
          server: acrServer
          identity: acrPullIdentityId
        }
      ]
      secrets: [
        {
          name: 'redis-password'
          value: redisPassword
        }
      ]
      ingress: {
        // external: true on this internal environment publishes Redis only at
        // the environment's internal load balancer in the VNet (no public
        // endpoint). false would hide it from App Service ("Clients elsewhere in
        // the virtual network receive an HTTP 404 response"). See azure/README.md.
        external: true
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
          // The image's entrypoint renders redis.conf from these variables
          // (no persistence, volatile-lfu, protected mode, ACL: FLUSHALL,
          // CONFIG, KEYS, DEBUG... denied for the app user).
          env: [
            {
              name: 'REDIS_PASSWORD'
              secretRef: 'redis-password'
            }
            {
              name: 'REDIS_MAXMEMORY'
              value: maxmemory
            }
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

// Alerts (Container Apps platform metrics). RestartCount and WorkingSetBytes
// are in Learn's Microsoft.App/containerapps metrics list; RestartCount is
// cumulative, so confirm on qa that the restart alert resolves.
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
