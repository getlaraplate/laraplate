# Releasing

Releases of the application and of its modules are cut by the maintainers with private tooling. There is nothing to run from a public checkout.

What a contributor needs to know:

- Write [conventional commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `feat!:` for a breaking change). The release level and the changelog are derived from them, so the commit subject is what readers of the changelog will see. Anything a release note should say belongs in the commit body.
- Do not edit `CHANGELOG.md` by hand: it is regenerated in full from history at every release and the edit is discarded.
- Do not change the `version` field of any `composer.json` and do not create tags: the maintainers do both.
