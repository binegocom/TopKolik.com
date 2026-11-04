# Topkolik — Architecture Decision Records, Release Notes & Dependency Audit Protocol

## 1. Architectural Decision Records (ADR) Protocol

All architectural, infrastructural, domain boundary, and security policy decisions must be documented through an Architecture Decision Record (ADR) prior to production deployment or major refactoring.

### 1.1 ADR Lifecycle
1. **Proposed**: Outlined in a pull request with problem statement, alternatives considered, security implications, and trade-offs.
2. **Approved**: Formally accepted and ratified by project leadership with dated decision entries in `docs/product/DECISION_REGISTER.md`.
3. **Superseded**: Replaced by a subsequent decision with explicit forward/backward links.
4. **Deprecated**: Obsolete capability phased out.

### 1.2 Required ADR Structure
- **Title & Context**: What technical, regulatory, or business context forces this decision?
- **Decision**: The chosen architecture, technology, or governance constraint.
- **Consequences**: Positive trade-offs, negative costs, latency impacts, and migration requirements.
- **Security & Privacy Assessment**: KVKK/GDPR implications, authentication boundaries, and data retention policies.

---

## 2. Release Notes Protocol

Topkolik follows **Semantic Versioning 2.0.0** (`MAJOR.MINOR.PATCH`) combined with the **Keep a Changelog** standard.

### 2.1 Release Categories
- **`Added`**: New consumer, admin, or API capabilities (e.g., Turkish match arena support, prediction leaderboards).
- **`Changed`**: Alterations to existing features or API contracts.
- **`Fixed`**: Bug fixes, race condition patches, or visual adjustments.
- **`Security`**: Vulnerability remediations, dependency upgrades for published CVEs, or access control hardening.
- **`Deprecated`**: APIs scheduled for upcoming sunset.

### 2.2 Release Tagging Gate
A release may only be tagged when:
1. All roadmap tasks associated with the release milestone are marked `done` with verifiable evidence in `ROADMAP.json`.
2. All CI workflows (`editorial-guard`, `laravel-api`, `clients-ci`, `dev-infrastructure`) pass green without bypassed checks.
3. Zero high or critical security vulnerabilities exist across `composer audit` and `npm audit`.

---

## 3. Dependency Audit and Security Patching Protocol

To protect fans, staff, and systems from software supply chain attacks, Topkolik enforces continuous dependency auditing.

### 3.1 Automated Audit Gates
- Every commit affecting dependencies must execute `bash scripts/audit-dependencies.sh`.
- Automated CI blocks pull requests if any package contains an advisory with severity `High` or `Critical`.

### 3.2 Security Advisory Response SLA
- **Critical (CVSS 9.0 - 10.0)**: Surgical patch or dependency upgrade deployed within **24 hours**.
- **High (CVSS 7.0 - 8.9)**: Remediation within **72 hours**.
- **Moderate / Low**: Handled within the regular scheduled sprint release.
