# Data-exit policy and local privacy transform

## Data-exit policy (`Agent\DataExitPolicy`)

An administrator-configured policy that controls whether data may leave the
system through outbound seams (model, tool, broker). Each category resolves to
`allow`, `obfuscate`, or `block`.

- **Inactive by default**: every category is `allow` and current behavior is
  preserved.
- **Active policy**: enforced automatically at the seam. `block` rejects the
  outbound action before it runs; a user selecting an endpoint cannot bypass it.
- Configured through the existing settings table (`data_exit.active`,
  `data_exit.policy`); no new migration is required.

This is a policy decision point, not comprehensive DLP. It does not inspect or
classify arbitrary content, and it does not replace access controls.

## Local obfuscate/recover (`Agent\PrivacyTransform`)

Obfuscation replaces a sensitive value with an opaque token. The
token-to-value mapping is kept only in local process memory and is inspectable,
but it is never transmitted outbound.

- Recovery is explicit: an unknown or missing token throws; there is no silent
  fallback or substitute.
- Tokens are single-request/local only; there is no cross-request key recovery
  by design.
