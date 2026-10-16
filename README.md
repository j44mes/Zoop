# Zoop

Zoop is a new software project, ready to develop with GitHub and Cursor Cloud Agents.

> **Project stage:** Repository bootstrap. The product requirements and technical stack have not been chosen yet. This is intentional: an agent should not invent an application before the goal is defined.

## Start building

1. Open this repository in [Cursor Cloud Agents](https://cursor.com/agents) or clone it locally:
   ```bash
   git clone https://github.com/j44mes/Zoop.git
   cd Zoop
   ```
2. Describe what Zoop should do, its users, and any constraints.
3. Ask the agent to propose the smallest useful implementation, including a development command, a test command, and a deployment plan.
4. Work on a feature branch and open a pull request for review.

Cursor instructions are in [AGENTS.md](AGENTS.md). Agents should read that file before making changes.

## Current repository structure

| File | Purpose |
| --- | --- |
| `README.md` | Project overview and getting started |
| `AGENTS.md` | Shared instructions for coding agents |
| `.editorconfig` | Consistent basic editor formatting |
| `.gitignore` | Keep secrets, dependencies, and generated files out of Git |
| `.github/pull_request_template.md` | Review checklist for proposed changes |
| `.github/workflows/repository-checks.yml` | Basic repository checks on pushes and pull requests |

## Development environment

There is **no application runtime yet** and therefore no installation, build, or test commands. Once a stack is chosen, the first feature PR should:

- Document prerequisite tools and exact install, run, test, and build commands here.
- Include dependency lockfiles where the ecosystem supports them.
- Add meaningful automated tests and CI that actually run those tests.
- Set up the [Cursor cloud development environment](https://cursor.com/docs/cloud-agent/setup) using those same reproducible commands.
- If configuration is required, commit an `.env.example` listing **names only** and put real secrets in Cursor's environment settings or the deployment provider. Never commit real credentials.

## Working agreement

- Create feature branches rather than pushing application changes directly to `main`.
- Open a PR with a summary, tests performed, and any changes needed before deployment.
- Keep changes small and reversible. Do not run destructive commands against live services without explicit approval.
- If requirements are unclear, record assumptions in the PR and avoid unrelated functionality.

## Security

Never commit private keys, access tokens, passwords, production data, or local environment files. Review every PR before merging. This repository's GitHub visibility and Cursor GitHub App permissions must be managed separately in account settings.
