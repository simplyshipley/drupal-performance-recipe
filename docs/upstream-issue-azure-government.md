# Draft comment for drupal.org #3427718 (NOT POSTED)

Target: https://www.drupal.org/project/azure_cdn_purge/issues/3427718
("Support Multiple Azure Cloud Environments", Needs review, MR !6). This is a follow-up
comment for that issue, not a new issue. Bo decides whether and when to post it.

---

Tested MR !6 (commit `9859fc45a6e117ab16d7ad2aa37f25df090bc791`) applied to 1.0.4 on
Drupal 10.6.17. The Government hosts match Microsoft's endpoint table
(https://learn.microsoft.com/en-us/azure/azure-government/compare-azure-government-global-azure#guidance-for-developers):
`login.microsoftonline.us` for Microsoft Entra ID and `management.usgovcloudapi.net` for Azure
Resource Manager.

One problem, plus some small additions, in the attached follow-up diff (applies on top of MR !6):

1. **Existing sites break after updating.** `azure_environment` does not exist in a site's
   config until the settings form is saved again, so in `AzurePurger::purgeChunk()` neither
   `if` runs, `$base_auth_url` / `$base_api_url` are undefined, and every purge fails. The
   follow-up maps the two values through an array and falls back to `azureCloud` for a
   missing or unknown value, so sites that do nothing keep working exactly as before. The form
   defaults the select to `azureCloud` for the same reason.
2. **Scope.** The token scope must match the cloud (`https://management.usgovcloudapi.net/.default`
   for Azure Government; Microsoft's pattern is `resource_manager + "/.default"`,
   https://learn.microsoft.com/en-us/azure/developer/python/sdk/azure-sdk-sovereign-domain).
   The follow-up uses the configured `scope` if one is set, and otherwise derives it from the
   selected cloud's Resource Manager host.
3. **Optional host overrides.** `login_host` and `management_host` (full URLs), used instead
   of the cloud's defaults when set. Useful for other sovereign clouds and for tests against a
   local mock endpoint.
4. **Config schema.** The module ships no schema for `azure_cdn_purge.settings`; the follow-up
   adds one covering all keys, including the new ones.

How it was tested: a mock Azure endpoint answered both the commercial and the Government hosts
and logged every request. With `azure_environment: azureUSGovernmentCloud` and an empty scope,
`drush p:queue-work` sent the token request to `login.microsoftonline.us` with scope
`https://management.usgovcloudapi.net/.default`, and
`POST /subscriptions/.../providers/Microsoft.Cdn/profiles/.../afdEndpoints/.../purge` to
`management.usgovcloudapi.net`. With the default setting the requests went to the commercial
hosts, as before. Not yet tested against a real Azure Government Front Door profile.

Still open (not in this diff): no update hook sets `azure_environment` for existing sites (the
fallback makes one unnecessary), and there are no automated tests.

Attached: `azure_cdn_purge-3427718-followup-default-cloud.patch`
