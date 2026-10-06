---
title: "Reconciliation: PRD vs domain research (RMG production and executive KPIs)"
created: 2026-10-02
source: ../../research/domain-rmg-production-and-executive-kpis-2026-10-02/research.md
targets:
  - prd.md
  - addendum.md
---

# Reconciliation: PRD vs domain research

Status values: **present**, **missing**, **weakened**, **overstated**, **mismatch**. Section references are to the research (left) and the PRD (right) unless the addendum is named.

## 1. Recommendations R1–R7 (research §8)

| Research location | What it says | PRD status | Suggested fix |
|---|---|---|---|
| §8 R1 | Take §3 to Session 2 as a ranking sheet; client picks a top 10 and marks each "needed live (~30 s)" or "daily/period" | **weakened** | O3 carries the top-10 ranking, but nothing asks the client to mark each KPI live or periodic. The template table assigns Refresh Class defaults itself. Extend O3 (or O1) to "client marks each ranked KPI as live or daily/period". Label the §4.15 Refresh Class column "proposed default, pending O3". |
| §8 R2 | The client selects a variant for each STD+VAR KPI. For PRACTICE and CLIENT KPIs the client supplies the formula | **present** (minor mismatch) | FR-18 (variant or custom formula) and O4 cover it. But O4 says "variant choices (V2–V16)", while the glossary and FR-18 cite V1–V17. V1 (SMV vs SAM, time study vs GSD) directly affects Line Efficiency, so change O4 to "V1–V17". |
| §2 + §8 R2 | Each KPI carries a definition status (STD / STD+VAR / PRACTICE / CLIENT) that tells the client what to do | **missing** | Add a "Definition status" column to the §4.15 table: P4 STD, P2 STD+VAR, P15 STD, W3 STD, P11 PRACTICE, Q1 STD / Q2 STD, Q3 STD+VAR, W1 STD+VAR, P7 STD. Optionally record it in FR-16 metadata, so PRACTICE KPIs are visibly client-authored. |
| §8 R3 | KPI definitions are versioned metadata holding the chosen formula, unit, direction, target, aggregation rule (Σnum ÷ Σden) and freshness class | **present** | FR-16, FR-17, FR-21 and the glossary cover every element. No change needed. |
| §8 R4 | Freshness is configurable per KPI or widget. RT is used **only where the source captures events at that rate**. Show "data as of", not just "refreshed at" | **weakened** | FR-50, FR-52 and O24 cover configurability and Data-as-of. Nothing stops an author or designer giving a KPI the Live class when its source only has hourly boards: §4.15 defaults P2, W3 and P11 to Live without conditions. Add an FR-50 consequence: a KPI can only be set to Live if its Dataset is declared as event-captured (per O1). Otherwise its default falls to hourly or Periodic. |
| §8 R5 | Session 2 must establish the capture method (hourly board vs bundle/piece scan vs IoT) and whether SMV per style and attendance per line exist | **present** | Covered by O1 and O2, §6 and the §9 risks. No change needed. |
| §8 R6 | Map each KPI to its module (PROD vs future QA/HR/ORD/SHIP/INV/FIN). Mark KPIs whose module doesn't exist as post-MVP or "on module availability" | **weakened** | §11.2 defers the §3.4 KPIs, but four of the nine MVP templates depend on future modules: DHU/Quality (QA), Rejection (QA + SHIP for the cut−shipped variant), Downtime (MNT/INV reason codes), and Attendance (HR). The PRD leaves them as open source questions (O2, O5) and never labels them "on module availability". Add a "Module (research)" column to §4.15, and state that a template whose module is absent ships as Unavailable at launch. |
| §8 R7 | Use the §6 glossary as the PRD's domain vocabulary. Mark unsourced trade terms for client confirmation | **missing** | The PRD §3 glossary is platform-only. It uses SMV, SAM, DHU, WIP, helper, QC-passed, overall/on-standard, cut qty, downtime/NPT, produced/available minutes, defective and reject without defining them. Add a "Domain terms" subsection that imports the needed research §6 entries, at minimum: SMV, SAM (with the V1 conflict), produced minutes, available minutes, overall vs on-standard efficiency, helper, NPT/lost time, WIP, defect vs defective, DHU, reject vs alteration, bundle, hourly production board. |

## 2. Cross-dimension insights (research §7)

| Research location | What it says | PRD status | Suggested fix |
|---|---|---|---|
| §7.1 | Executive KPIs are period metrics. The 30 s target suits a "factory today" view. Freshness should be set per KPI | **present** | Covered by §1 (two kinds of numbers), §4.10, UJ-1 and UJ-4. No change needed. |
| §7.2 | The production module's capture method decides whether "live" is real. With hourly counts, every RT KPI is effectively hourly | **present** (enforcement weakened) | The point is carried in §4.10, the §9 risk table, NFR-1 and SM-C2. The PRD has no "hourly" class, however (see the refresh-class row in §5 below), so with board capture Live Widgets will poll every 30 s and show a Data-as-of up to an hour old. Add an explicit statement that under hourly capture, Live KPIs effectively become hourly. |
| §7.3 | Master data is the hidden dependency: SMV per style and attendance per line | **present / weakened** | §6 and FR-19 carry it. Missing are the research data-quality prerequisites: SMV **versioned per style and method change** (P1); attendance **captured by shift start**; **line transfers tracked** so staff aren't double counted (P2, P3). Add them as Data Prerequisites or O2 sub-questions. |
| §7.4 | Correct aggregation is part of the definition: Σnum ÷ Σden, never average percentages. This also applies to DHU and other ratios | **present** | Covered by FR-17 (with a worked example), the glossary, FR-54 (Snapshots store numerator and denominator), and addendum §B. No change needed. |
| §7.5 | With no history, trends start at go-live | **present** | Covered by §4.11, FR-55 ("Not enough history") and O8. No change needed. |

## 3. Data-capture implications (research §5)

| Research location | What it says | PRD status | Suggested fix |
|---|---|---|---|
| §5 implication 1 | An honest ~30 s refresh is limited to P2–P6, P9, P11, P15 and W3, plus Q1/Q4 **only where digital QC exists** | **weakened** | The §4.15 DHU/Quality template is "Live or Periodic", and UJ-1 shows DHU by factory at "data as of 09:12:40". The research found digital end-line QC **not evidenced** in Bangladesh. Make the DHU default Periodic (hourly), with Live only if O5 confirms digital per-piece QC capture. |
| §5 implication 2 | Live needs the production module to capture per bundle, piece or machine | **present** | Covered by §4.10, NFR-1 and O1. No change needed. |
| §5 row "Manual hourly board" | Board capture bounds freshness to hourly or bi-hourly, however often the dashboard polls | **weakened** | Implied by Data-as-of (FR-52), but there is no requirement tying the polling interval to capture frequency, so SM-C2 is only a counter-metric. Add to FR-50: the minimum Freshness Interval for a Dataset is bounded by its declared capture frequency. |
| §5 row "Workstation vs gate attendance" | Operators present, the efficiency denominator, comes from workstation login reconciled to gate attendance | **missing** | O2 asks only whether attendance exists. Add: at what grain (line? shift start? workstation login?) and how often attendance is updated. This determines whether Line Efficiency can be Live at all. |
| §5 implication 3 | §3.4 KPIs and most of W1/W2/W6/W7 are daily to monthly | **present** | Covered by §11.2 and Attendance set to Periodic. No change needed. |
| §11 staleness | Adoption claims [41][43][45] are stale. Ask the client directly about their capture method rather than relying on the sector picture | **present** | O1 asks directly. Optionally add one line to §9 noting that sector adoption evidence is stale. |

## 4. MVP priority KPIs: research IDs and refresh classes (§4.15 vs research §3)

| Research location | What it says | PRD status | Suggested fix |
|---|---|---|---|
| §3.1 P4 | Target vs actual (achievement %). STD. Refresh **RT** (actual side). Module PROD + PLAN + IE. Daily target = shift min × operators × planned efficiency ÷ SAM; planned efficiency and learning-curve policy are prerequisites | **present** (prerequisites weakened) | The ID and Live class match. The prerequisites list only "Output, Target". Add: the target source (PLAN), or if targets are derived, SAM plus the planned-efficiency and learning-curve policy. |
| §3.1 P2 | Line efficiency %. STD+VAR. Refresh **RT if output scanned and attendance known at shift start; otherwise H**. Variants V2, V3, V4, plus the alternative "achieved ÷ target output" [5], plus V1 for the SMV basis | **weakened** | The ID matches. The refresh class drops the "otherwise H" condition. The variants omit V1 and the achieved ÷ target alternative. Change the refresh class to "Live if O1 confirms scan capture and per-line attendance at shift start; else hourly". Add V1 to the variants and mention the achieved ÷ target form. |
| §3.1 P15 | Throughput / hourly output. STD. RT (same conditions as P2) | **present** | The ID and class match. Add the capture condition: hourly under board capture. |
| §3.3 W3 | WIP per section/line. STD. **RT if line input and output both scanned; otherwise H** | **present / weakened** | The ID matches and the line-input prerequisite is carried. The class should read "Live if scanned, else hourly". Note that the section formulas (cutting, sewing, finishing) differ; the research gives one per section. |
| §3.1 P11 | Lost time / NPT / downtime %. **PRACTICE**. RT with terminal logging or machine idle sensing; **otherwise end of shift**. lost ÷ available is an unverified assumption. Needs a standard reason-code list | **weakened** | "Formula client-defined" correctly reflects PRACTICE. The "Live" default contradicts "otherwise end of shift". Make it "Live only with event logging (O5); else Periodic (per shift)". Add "standard reason-code list" as a prerequisite. |
| §3.2 Q1 / Q2, §4 V6 | DHU (counts defects, can exceed 100) and Defective % (counts pieces, always ≤ DHU). Refresh **RT with digital capture, otherwise H**. Tally sheets must record defective pieces, not only defects | **weakened** | The IDs match. "Live or Periodic" is reasonable, but see the §5 row above for DHU. The template is named "DHU / Quality": require the published KPI name and unit to state which measure it is (DHU vs Defective %), because the research says "defect %" is ambiguous in the field. Add the prerequisites "every checked piece counted; checkpoints kept separate; defect-code master". |
| §3.2 Q3, V7 | Rejection %. STD+VAR. Refresh **P** (order level) / RT (stage, if scanned). Base: rejects ÷ cut qty, or the cut − shipped proxy (needs SHIP); the stage-level variant is unverified | **present / weakened** | The ID, V7 and Periodic match. The prerequisites omit shipped qty (a future SHIP module) for the proxy variant and the reject-reason coding (material vs process). Add both, and mark the proxy variant "on module availability". |
| §3.3 W1 | **Absenteeism %**. STD+VAR (V9). Refresh **D**. Module **HR (future)** | **mismatch** | The PRD row reads "W1 or 'manpower present'". W1 is absenteeism; "manpower present" is not a catalogue KPI (it is the efficiency denominator input, from the §5 attendance row). Either split the row into W1 Absenteeism % (V9, D, HR) and a non-catalogue "Manpower present" count (PRACTICE/CLIENT, D), or keep O3 open and drop the W1 citation until the meaning is confirmed. Mark the HR module dependency. |
| §3.1 P7 | Labour/machine productivity. STD. Refresh **D**. Formulas: output ÷ manpower, output ÷ machines, UPPH. **Not comparable across styles with different SMV** | **present / mismatch in §6** | The ID and Periodic match. "Manpower scope" is reasonable but has no V-number; list the documented forms (per person, per machine, UPPH). PRD §6 says that without SMV "Line Efficiency **and Productivity** are Unavailable", but P7 as researched does not use SMV. Fix §6, or state that the client's Productivity definition is SMV-based (which would make it P5 or P2, not P7). Add the cross-style comparability caveat to the template. |
| §2 refresh classes | Five classes: RT, H, D, P, M | **weakened** | The PRD glossary collapses these to Live and Periodic, and loses **H** (hourly), the realistic class for board capture and inline QC. Add an Hourly class, or define Periodic to include hourly explicitly, and map the research classes to it (RT→Live, H→Hourly, D/P→Periodic, M→master data, not a KPI). |

## 5. Formula variants V1–V17 relevant to the MVP KPIs (research §4)

| Research location | What it says | PRD status | Suggested fix |
|---|---|---|---|
| §4 V1 | SMV vs SAM naming conflict; time study vs GSD give different values | **missing** for the MVP | It underlies P2, P4 and the Unavailable rule, yet it isn't offered on the Line Efficiency template and O4 excludes it. Add V1 to the Line Efficiency and Production Achievement templates, and to O2 (which SMV basis the client holds). |
| §4 V2, V3, V4 | Line efficiency: lost time, headcount, output basis | **present** | FR-18 and §4.15 carry them. No change needed. |
| §4 V5 | Roll up by weighting, never by averaging percentages | **present** | Covered by FR-17. No change needed. |
| §4 V6 | DHU vs Defective % | **present** | §4.15 carries it. Add the naming and unit disambiguation (see §4 above). |
| §4 V7 | Rejection base | **present** | §4.15 carries it. Note that the proxy variant depends on SHIP. |
| §4 V9 | Absenteeism definitions | **present** | Attached to Manpower/Attendance. Valid only if O3 resolves that row to W1. |
| §4 V8, V10–V17 | RFT vs pass %, turnover, line balance, CPM, cut-to-ship direction, OTD date and unit, fabric, traffic-light | **n/a for MVP** | These are post-MVP KPIs. Keep the glossary reference "V1–V17"; fix O4's range. |
| §4 preface | "The PRD should require the platform to store the chosen definition as KPI metadata" | **present** | Covered by FR-16 (Variant choice and reason) and §7 Variant record. No change needed. |

## 6. Glossary terms the PRD relies on (research §6)

| Research location | What it says | PRD status | Suggested fix |
|---|---|---|---|
| §6 SMV / SAM | Defined, with the V1 conflict | **missing** | Used in UJ-1, FR-19, §4.15 and §6 but never defined. Import both, with the conflict note. |
| §6 DHU; defect / defective | Defects ÷ checked × 100; can exceed 100 | **missing** | Used in UJ-1, FR-17 and §4.15. Import both. |
| §6 WIP | Input − output per section | **missing** | Used in UJ-4, UJ-6 and §4.15. Import it. |
| §6 Overall vs on-standard efficiency; helper; produced / available minutes | Efficiency vocabulary | **missing** | Used in UJ-2, FR-17 and FR-18. Import them. |
| §6 Off-standard time / NPT / lost time | Downtime vocabulary | **missing** | The PRD says "Downtime" only. Define it as NPT / lost time and note that the formula is client-defined (P11 PRACTICE). |
| §6 Reject vs alteration | Unrepairable garment vs rework | **missing** | Rejection is an MVP template. Import the term. |
| §6 Hourly production board; bundle | Capture vocabulary | **missing** | Needed to phrase O1 and the Live condition precisely. Import both. |
| §6 last row; §8 R7 | Unsourced trade terms (PP sample, ETD/ETA, etc.) are flagged for client confirmation | **n/a** | The PRD doesn't use them. No change needed. |

## 7. Confidence and single-formula issues

| Research location | What it says | PRD status | Suggested fix |
|---|---|---|---|
| §1 caveat; §11 | Evidence is practitioner-heavy (one publisher dominates). Definitions must be re-confirmed against client practice | **missing** | Templates are presented as "documented Variants from the research" with no confidence note. Add one sentence to §4.15: template formulas are practitioner-sourced (medium confidence) and are confirmed per KPI by the owner (O4). |
| §3.1 P11 | Downtime % (lost ÷ available) is an unverified assumption | **present** | "Formula client-defined" is correct. No change needed. |
| §3.1 P2, §4 V2–V4 | Line efficiency has several valid definitions | **present** | FR-18 does not present one formula as correct. UJ-2's "overall, operators + helpers, QC-passed" is clearly labelled the client's approved choice. No change needed. |
| §3.1 P2 refresh | RT is conditional; otherwise H | **overstated** | §4.15 states "Live" unconditionally for P2, P15, W3 and P11. Qualify each, as in §4 above. |
| §5 digital QC not evidenced | Live DHU depends on digital end-line capture | **overstated** | UJ-1 shows live DHU by factory as the default experience. Either mark UJ-1's DHU as conditional on O5, or replace it with a production-only KPI. |
| §6 dependency table (PRD) | "Without SMV, Line Efficiency **and Productivity** are Unavailable" | **mismatch** | P7 (the research ID cited for Productivity) does not need SMV. Correct §6, or reclassify Productivity. |
| §3.1 P4 | Planned efficiency is often ~75% [13] | **n/a** | UJ-2's 65% target is illustrative and not presented as a benchmark. No change needed. |
| §3.2 Q1 | Vendor DHU bands are not adopted as benchmarks | **present** | The PRD cites no DHU benchmark. No change needed. |
