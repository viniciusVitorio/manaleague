# Opt-in only; activation also requires the release store and runtime registry.
variable "enable_deploy_broker" {
  type    = bool
  default = false
  validation {
    condition     = !var.enable_deploy_broker || (var.enable_release_artifacts && var.enable_deploy_runtime_registry)
    error_message = "Deploy broker requires the release artifact bucket and deploy runtime registry."
  }
}
