---
title: "Client Session 2 — Decision Kit"
project: RMG Dynamic Dashboard Platform
created: 2026-10-02
status: ready-for-session
inputs:
  - ../briefs/brief-rmg-dashboard-platform-2026-10-02/brief.md (v2)
  - ../briefs/brief-rmg-dashboard-platform-2026-10-02/addendum.md (§C)
  - ../research/domain-rmg-production-and-executive-kpis-2026-10-02/research.md
---

# Client Session 2: Decision Kit

> **Historical (2026-10-05).** This kit was written for the RMG client engagement (brief v3). The product is now domain-agnostic (brief v4, PRD v3.1). For a new customer, use the **pending client inputs** list in PRD §12 instead. The RMG-specific sheets apply only if an RMG deployment is configured as a use case.


**Goal:** leave the session with the eight decisions the PRD needs. Anything the client cannot answer is recorded as **TBD**, with an owner and a due date. Do not guess.

**Who should attend:**
- executive sponsor (KPI ranking)
- production / IE lead (KPI definitions, SMV)
- RMG application owner or IT (data capture, database/API access)
- HR / attendance owner
- quality lead

**Suggested length:** about 2 hours. Sections 1 and 3 can be sent ahead as pre-work.

| # | Decision | Section |
|---|---|---|
| 1 | Data capture frequency and source | §1 |
| 2 | SMV and attendance availability | §2 |
| 3 | Top 10 KPIs | §3 |
| 4 | KPI definitions and formulas | §4 |
| 5 | ~30-second vs daily/period refresh per KPI | §3 (column) |
| 6 | KPI and dataset governance | §5 |
| 7 | Database vs API/replica access | §6 |
| 8 | Historical data requirement | §7 |

**Order matters.** Settle §1 and §2 before §3. A KPI can only be "live" if its source data is captured that often (research §5), and most efficiency and cost KPIs need SMV and attendance data (research §7).

---

## §1. Data capture: frequency and source

For each data item, ask the client to **show one real record or screen**.

| Data item | Captured today? (Y/N) | Method: manual board / paper → keyed, bundle scan (barcode/QR/RFID), machine IoT, tablet, ERP entry | How often: per piece, per bundle, hourly, shift, day | Where it lives: RMG app table, API endpoint, other system | Lowest level: factory / floor / line / workstation / machine | Owner |
|---|---|---|---|---|---|---|
| Sewing output (pieces) | | | | | | |
| Line input / loading (for WIP) | | | | | | |
| Hourly targets | | | | | | |
| Cutting quantity per order | | | | | | |
| Finishing / packing output | | | | | | |
| Quality: pieces checked, defects, defectives | | | | | | |
| Lost time / downtime with reasons | | | | | | |
| Attendance (per line, by role) | | | | | | |
| Orders / POs, ex-factory dates | | | | | | |
| Shipments | | | | | | |

Also capture:
- **Events/APIs:** what endpoints, webhooks, queues or change events does the RMG application expose? Is there documentation?
- **Future modules:** which modules come next (quality, HR, orders, shipment, inventory, finance), and roughly when?

## §2. SMV and attendance availability

| Question | Answer |
|---|---|
| Is an **SMV/SAM per style** stored in the RMG application? (Y/N/partly) | |
| If yes: per garment or per operation? Set by time study or GSD? Versioned when the style changes? | |
| Does the client say "SMV" or "SAM", and do they include allowances? (research V1) | |
| Is **attendance per line** recorded? By whom, at what time, and in which system? | |
| Are line transfers during the shift recorded? | |
| Are helpers (and ironmen) counted as line manpower? (research V3) | |
| **If SMV or attendance is missing:** should efficiency, capacity and cost KPIs be deferred, or should the data be captured as part of this project? | |

## §3. KPI ranking sheet: top 10, plus refresh need

Instructions for the client:
- Rank the **10 most important KPIs (1 = most important)**. Leave the rest blank.
- For each ranked KPI, mark **Refresh**: **L** = live (~30 s), **D** = daily / per shift, **P** = weekly / monthly / at order close.
- Research guidance: **L** is only realistic for KPIs marked RT, and only if §1 shows per-bundle, per-piece or machine capture.

Codes (from the research catalogue):
- Audience: E = executive, Mg = management, F = floor.
- Research refresh class: RT = can be ~30 s if events are captured; H = hourly; D = daily; P = period; M = master data.
- Module: modules marked *future* don't exist yet.

| ID | KPI | Audience | Research refresh class | Module | **Rank** | **Refresh (L/D/P)** | Notes |
|---|---|---|---|---|---|---|---|
| P2 | Line efficiency % | Mg/F | RT | PROD + IE + HR | | | |
| P3 | Factory / floor efficiency % | E | RT | PROD + IE + HR | | | |
| P4 | Target vs actual output (achievement %) | E/F | RT | PROD + PLAN | | | |
| P5 | Produced vs available minutes | Mg | RT | PROD + IE + HR | | | |
| P6 | Operator efficiency % | F | RT* | PROD + IE + HR | | | *needs workstation scan |
| P7 | Labour / machine productivity | Mg/E | D | PROD + HR | | | |
| P8 | Capacity utilisation | E | P | PLAN + ORD *(future)* | | | |
| P9 | Machine utilisation | F | RT* | PROD + MNT | | | *needs IoT |
| P10 | Sewing OEE | Mg | RT | PROD + QA + MNT | | | |
| P11 | Lost time / NPT / downtime % | Mg | RT | PROD + MNT | | | |
| P12 | Style changeover time | Mg | D | PROD + PLAN | | | |
| P13 | Line balancing ratio / bottleneck | F | H | IE | | | |
| P14 | Man-to-machine ratio | E | P | HR + IE | | | |
| P15 | Hourly output / throughput | F (feeds E) | RT | PROD | | | |
| Q1 | DHU (defects per hundred units) | E/Mg | RT | QA *(future?)* | | | |
| Q2 | Defective % | Mg | RT | QA | | | |
| Q3 | Rejection % | E/Mg | P | QA + PROD + SHIP | | | |
| Q4 | Right first time (RFT) | Mg | RT | QA | | | |
| Q5 | Traffic-light inline status | F | H | QA | | | |
| Q6 | Final audit (AQL) pass rate | E | P | QA + SHIP | | | |
| Q7 | Buyer inspection failure rate | E | P | QA + SHIP | | | |
| Q8 | Top defects (Pareto) | Mg | D | QA | | | |
| W1 | Absenteeism % | E/Mg | D | HR *(future)* | | | |
| W2 | Labour turnover % | E | P | HR *(future)* | | | |
| W3 | WIP (pieces) per section/line | Mg (E by factory) | RT | PROD | | | |
| W4 | WIP days / throughput time | Mg | RT/H | PROD | | | |
| W5 | Operator utilisation | F | RT | PROD + IE | | | |
| W6 | Overtime % | E | D/P | HR | | | |
| W7 | Cost per minute (CPM) | E | P | FIN + HR + IE | | | |
| X1 | On-time delivery (OTD) | E | P | SHIP + ORD *(future)* | | | |
| X2 | On-time in-full (OTIF) | E | P | SHIP + ORD | | | |
| X3 | Cut-to-ship ratio | E | P | PROD + SHIP | | | |
| X4 | Order-to-ship ratio | E | P | ORD + SHIP | | | |
| X5 | Order lead time | E | P | ORD + SHIP | | | |
| X6 | Marker efficiency | Mg | P | PROD (CAD) | | | |
| X7 | Fabric utilisation / consumption variance | E | P | INV + PROD | | | |
| X8 | Capacity booking / order book | E | P | PLAN + ORD | | | |
| X9 | FOB value shipped / produced value | E | P | SHIP + FIN | | | |
| X10 | CM / margin | E | P | FIN + IE | | | |
| X11 | Air-freight / late-shipment cost | E | P | SHIP + FIN | | | |
| X12 | Shipment pipeline status | E/Mg | D | SHIP + PROD | | | |
| X13 | Buyer scorecard | E | P | ORD + QA + SHIP | | | |
| — | *Client-proposed KPI* | | | | | | |
| — | *Client-proposed KPI* | | | | | | |

*(P1 SMV is master data, not a ranked KPI; see §2.)*

## §4. KPI definitions: choose a variant for each top-10 KPI

Complete this only for the KPIs ranked in §3. Full variant detail is in research §3–§4.

| Variation | Applies to | Option A | Option B | **Client choice: A / B / own formula** |
|---|---|---|---|---|
| V2 | Efficiency: lost time | Overall (includes lost and off-standard time) | On-standard (excludes it) | |
| V3 | Efficiency: manpower | Operators only | Operators + helpers (± ironmen) | |
| V4 | Efficiency: output | QC-passed pieces | All stitched pieces | |
| V5 | Factory roll-up | Σ produced ÷ Σ attended (weighted) | *(averaging percentages: research flags this as an error)* | |
| V6 | Quality rate | DHU (defects) | Defective % (pieces) | |
| V7 | Rejection base | Rejects ÷ cut qty per order | Cut − shipped | |
| V8 | First-pass | RFT (first presentation) | Lot pass % | |
| V9 | Absenteeism | ILO: unplanned incl. sick, excl. holiday | Approved leave removed from denominator | |
| V10 | Turnover | Workers only | All staff | |
| V12 | CPM | Cost ÷ produced minutes | Cost ÷ available minutes | |
| V13 | Cut-to-ship | Cut ÷ shipped (target 1) | Shipped ÷ cut × 100 (~98%) | |
| V14 | OTD reference date | Ex-factory date | Buyer-requested / confirmed date | |
| V15 | OTD unit | Per order | Per PO line | |
| V16 | Fabric consumption | Marked (CAD) | Achieved (bought ÷ shipped) | |

For each top-10 KPI also record:
- the target or threshold, if known;
- direction (higher is better / lower is better);
- the owner of the definition.

**Best evidence:** ask for one current report per KPI, and copy the formula from it.

## §5. Governance: who does what

Record one of these per cell: **C** = create, **E** = edit, **A** = approve, **P** = publish, **V** = view, **—** = no access.

| Action | Super/System admin | Data admin (IT) | KPI author (business) | Dashboard designer | Executive / CXO | Client (external?) |
|---|---|---|---|---|---|---|
| Register data source | | | | | | |
| Create / edit dataset | | | | | | |
| Create / edit KPI formula | | | | | | |
| Create / edit widget | | | | | | |
| Publish dashboard | | | | | | |
| Modify a published dashboard | | | | | | |
| Approve before publish (required? Y/N) | | | | | | |
| Raw SQL (allowed at all? Y/N) | | | | | | |

| Question | Answer |
|---|---|
| Is the KPI formula builder **fully no-code** (pick fields and operators)? | |
| When a KPI definition changes, do existing dashboards update automatically, or stay on the old version until republished? | |
| Is data visibility scoped by factory / floor / line / department / buyer? | |
| **Who are the "clients" among the users?** Internal management, or external buyers who must see only their own orders? | |
| Can executives personalise their own layout? | |

## §6. Data access: database vs API vs replica

The client chooses one option. Implications are noted for the architecture stage. This choice is not final until architecture.

| Option | What it means | Implication |
|---|---|---|
| A. Read-only connection to the **live** RMG database | Fastest to build | Dashboard queries add load to the operational system; needs strict query limits |
| B. Read **replica** of the RMG database | Same schema, isolated load | Replica lag adds to freshness (must stay well under 30 s) |
| C. RMG **APIs** only | Loose coupling | Limited by what the APIs expose; aggregation is harder; API rate limits |
| D. Change events / CDC into a platform-owned store | Event-driven; also builds history | More build effort; best fit for "live" and history together |
| E. Mix (state it) | | |

| Question | Answer |
|---|---|
| Chosen option(s) | |
| Database engine and version of the RMG application (PostgreSQL? Laravel?) | |
| Are a read-only user and network access possible? Any security or IT policy constraints? | |
| Can the RMG application emit events on data change? | |

## §7. Historical data requirement

| Question | Answer |
|---|---|
| Why is there no history today: never stored, purged, or not migrated? | |
| Do executives need **trends and comparisons** (today vs yesterday, month to date, year on year) in the MVP? | |
| Should the platform **keep its own snapshots from go-live**? At what grain (hourly / shift / daily per KPI and dimension)? | |
| Is any backfill possible (exports, Excel, archives)? | |
| Retention period required (months/years)? | |

---

## Return format

Paste the completed sections back, or a summary per section. Raw notes or photos of the sheets are fine. Anything unanswered stays **TBD** with an owner. Decisions then go into **brief v3**, which becomes the PRD input.
