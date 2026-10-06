# Traceability: capabilities, requirements, architecture, epics

PRD IDs are from PRD v3.3. AD IDs are from `ARCHITECTURE-SPINE.md`. Epic numbers are from `../../planning-artifacts/epics.md` (9 user-value epics, 155 stories). The earlier 11-epic split in `SOLUTION-DESIGN.md` §23 is superseded.

| CAP | PRD requirements | Binding ADs | Epic |
| --- | --- | --- | --- |
| CAP-1 | FR-1–FR-4 | AD-3, AD-4, AD-20, AD-31 | 1 |
| CAP-2 | FR-5–FR-7, FR-40 | AD-4, AD-5, AD-18 | 1, 6 |
| CAP-3 | FR-8 | AD-7, AD-30 | 2, 3 |
| CAP-4 | FR-9–FR-13 | AD-6–AD-10, AD-19, AD-26 | 2 |
| CAP-5 | FR-14–FR-20 | AD-13, AD-28, AD-30 | 3, 6 |
| CAP-6 | FR-21–FR-31 | AD-9–AD-12, AD-23, AD-33 | 3, 5 |
| CAP-7 | FR-32, FR-33 | AD-21, AD-32 | 3, 5 |
| CAP-8 | FR-34–FR-37 | AD-15, AD-21, AD-25, AD-33 | 3, 4, 5 |
| CAP-9 | FR-38–FR-44 | AD-5, AD-13, AD-14, AD-25 | 3, 6 |
| CAP-10 | FR-45–FR-48 | AD-13, AD-14 | 7 |
| CAP-11 | FR-49–FR-59 | AD-14, AD-15, AD-20, AD-27 | 4, 7 |
| CAP-12 | FR-60–FR-62 | AD-7, AD-8, AD-15, AD-16, AD-25, AD-26 | 2, 4 |
| CAP-13 | FR-63, FR-64 | AD-4, AD-16, AD-29 | 8 |
| CAP-14 | FR-65, FR-66 | AD-1, AD-24 | 8 |
| CAP-15 | FR-67, FR-68 | AD-18, AD-24 | 1, 8 |
| CAP-16 | NFR-3, NFR-6, NFR-13, NFR-14 | AD-1, AD-17, AD-22, AD-32, AD-34 | 1, 9 |

Cross-cutting NFRs: NFR-4 (security: AD-3, AD-6, AD-19, AD-30, AD-31), NFR-5 (data protection: AD-9), NFR-7 and NFR-8 (accessibility and browsers: AD-21, EXPERIENCE.md), NFR-10 (theming: DESIGN.md tokens), NFR-1 and NFR-2 (freshness and performance: AD-15, AD-26; targets TBD).

## Epic split

| # | Epic | Capabilities |
| --- | --- | --- |
| 1 | Secure Workspaces and Access | CAP-1, CAP-2, CAP-15, CAP-16 |
| 2 | Connect Your APIs | CAP-3, CAP-4, CAP-12 |
| 3 | Build and Publish Your First Block | CAP-3, CAP-5, CAP-6, CAP-7, CAP-8, CAP-9 |
| 4 | Live Personal Dashboards | CAP-8, CAP-11, CAP-12 |
| 5 | Rich Blocks and Governed Calculations | CAP-6, CAP-7, CAP-8 |
| 6 | Governed Publishing and Access | CAP-2, CAP-5, CAP-9 |
| 7 | Templates and Onboarding | CAP-10, CAP-11 |
| 8 | Find, Be Notified and Operate | CAP-13, CAP-14, CAP-15 |
| 9 | Production-Ready Deployment | CAP-16 |
