---
title: 'domain research: RMG production and executive dashboard KPIs'
type: 'domain'
topic: 'RMG production and executive dashboard KPIs and terminology'
decision: 'Propose a domain-grounded initial KPI catalogue and glossary for client Session 2 ranking and as PRD vocabulary input (not final KPI selection)'
source: 'native run'
status: complete
preset: 'standard'
validation: 'normal'
created: '2026-10-02'
updated: '2026-10-02'
claims_verified: 7
claims_unverified: 4
claims_disputed: 1
---

# Domain research: RMG production and executive dashboard KPIs

**Decision this research serves:** propose a domain-grounded **candidate** KPI catalogue and glossary for the client to rank in Session 2, and supply domain vocabulary for the PRD. This report does **not** select the client's KPIs.

## 1. Executive summary

**What the evidence says to do.** Take the catalogue of 43 candidate KPIs into Session 2 (§3). Present each one with its **formula variants side by side**, and ask the client to (a) rank the KPIs and (b) **choose their own definition** wherever variants exist. Do not let the platform hard-code any single formula. The KPI model must store a client-chosen definition, and the configurable metric layer the brief already proposes is the right place for that.

**The three findings that drive that answer:**

1. **Many core RMG KPIs have more than one definition in active use.**
   - Line efficiency is calculated with or without lost time [9][14], with or without helpers in the headcount [7][14], and on QC-passed output or on all output [7].
   - Cut-to-ship ratio is published in **opposite directions**: cut ÷ shipped, aiming for 1 [11][36], and shipped ÷ cut × 100, aiming for about 98% [12].
   - On-time delivery is measured against the ex-factory date, the buyer's requested date, or the date the factory confirmed [37][38][39].
   - DHU and "defect %" are routinely confused, even though the same sample gives 30 vs 7.5 [18].
   - The industry standardises the **concepts**, not the arithmetic. Every variant KPI is therefore a **client-definition decision**.
2. **The ~30-second target fits only one class of KPI.** Live floor-flow metrics (output, achievement %, efficiency so far today, WIP, downtime) can honestly refresh every ~30 s, and **only** when the production module captures events per bundle or per piece by scanner, RFID or machine sensor [16][41][42][43]. With manual hourly or bi-hourly boards, freshness is bounded by how often the board is updated, however often the dashboard polls [16]. Most **executive** KPIs (OTD/OTIF, cut-to-ship, lead time, fabric use, CPM/CM, turnover, AQL pass rate) are built from events or are period measures. They are meaningful **daily to monthly**, whatever the capture technology.
3. **Almost every efficiency, capacity and cost KPI depends on master data the current production module may not hold.** The key items are an **SMV/SAM per style** (the denominator basis for efficiency, capacity, CPM and CM [1][6][34][35]) and **attendance per line** [7][43]. Several high-value executive KPIs also depend on **future modules** (quality, HR/attendance, orders, shipment, stores, finance).

**The biggest caveat.** Evidence is **practitioner-heavy**. One publisher, Online Clothing Study, supplies many definitions, and other blogs copy it. Peer-reviewed full texts, listed companies' annual reports and BGMEA/BKMEA material **could not be retrieved** in this round. Core formulas were spot-checked against independent publishers. Confidence is marked per claim, and gaps are listed in §8.

## 2. How to read the catalogue

**Definition status.** This answers your requirement to separate industry-standard definitions from client-specific ones.

| Tag | Meaning | What the client must do |
|---|---|---|
| **STD** | The core formula is consistent across independent publishers | Confirm it |
| **STD+VAR** | The concept is standard, but documented variants change the number | **Choose a variant**, and record it as the client definition |
| **PRACTICE** | Widely used term, but no garment-specific formula was found in this research | **Define it.** The platform needs a client-authored formula |
| **CLIENT** | Exists only as a client or brand definition, such as a buyer scorecard | Supply the definition |

**Refresh class.** This is reasoning from the data-capture evidence in §5, not a quoted figure.

| Class | Meaning |
|---|---|
| **RT** | ~30 s refresh is meaningful *if* inputs are captured per piece, bundle or event (scan, RFID, IoT, tablet). Otherwise it degrades to hourly |
| **H** | Inputs change at most hourly (inspection rounds, manual boards) |
| **D** | Meaningful per shift or day |
| **P** | Period metric (weekly or monthly), or closes with an order |
| **M** | Master data, static per style or version |

**Audience.** **E** = executive/CXO headline. **Mg** = management (factory or department). **F** = floor or IE drill-down.

**Module.** **PROD** production (exists). Future modules: **QA** quality, **HR** workforce/attendance/payroll, **IE** engineering/SMV master, **PLAN** planning, **ORD** orders/merchandising, **INV** stores/inventory, **SHIP** shipment/commercial, **FIN** finance, **MNT** maintenance.

**Stored or calculated.** **S** stored raw or master value. **C** calculated from stored data.

## 3. Candidate KPI catalogue

### 3.1 Production and efficiency (D1)

| ID | KPI | Status | Formula and variants | Source data · granularity | Refresh | S/C | Module | Data-quality prerequisites | Decision · audience | Conf. |
|---|---|---|---|---|---|---|---|---|---|---|
| P1 | **SMV / SAM** (standard minute value / standard allowed minute) | STD+VAR | Garment SMV = Σ operation SMVs. Derived by time study (observed time × rating + allowances) [2][3] or by a predetermined motion-time system such as GSD [10]. **Naming conflict:** some sources treat SMV and SAM as the same thing [2]; one treats SMV as excluding allowances, with SAM = SMV + allowances [17] (snippet only) | Operation bulletin per style, cycle times, rating, allowance policy · operation × style | M | S | IE (future); PROD consumes it | One consistent method; documented allowance policy (allowances are factory policy [2]); a version per style or method change | Costing, capacity, targets; the basis of every efficiency KPI · Mg | M |
| P2 | **Line efficiency %** | STD+VAR | (Output × SMV) ÷ (manpower × working minutes) × 100 [3][6][14][52]. **Variants:** *overall* (includes lost and off-standard time) vs *on-standard* (excludes it) [9][14]; manpower = operators only [8] vs operators + helpers [7][14]; output = QC-passed vs all stitched [7]; alternatively achieved ÷ target output [5] | Output per line per hour, style SMV, headcount present by role, working and OT minutes, lost time with reasons · line × hour → shift/day | **RT** if line output is scanned and attendance is known at shift start; otherwise H | C | PROD + IE + HR (+QA if QC-pass output is used) | Accurate SMV; attendance captured by shift start; transfers between lines tracked [7]; one agreed output definition | Line performance, intervention, incentives [9] · Mg (daily), F (hourly) | **H** (core formula verified) |
| P3 | **Factory / floor efficiency %** | STD+VAR | Σ standard hours produced ÷ Σ hours attended × 100, weighted [7], or total minutes produced ÷ total minutes attended [11]. A **simple average of line efficiencies is a known error** [7] | As P2, for all lines · factory/floor × day | **RT** under the same conditions as P2 | C | PROD + IE + HR | As P2; no double counting of transferred staff [7] | **Executive headline for productivity** · **E** | M |
| P4 | **Target vs actual output (achievement %)**; hourly and daily target | STD | Hourly target at 100% = 60 ÷ operation SAM [6]. Daily target = shift minutes × operators × planned efficiency ÷ garment SAM [8]. Achievement % = actual ÷ target × 100 [5]. Planned efficiency is often about 75% [13]; learning-curve targets for new styles are common practice (unsourced this round) | Target plan, actual output · line × hour/day | **RT** (actual side, same capture conditions as P2) | Target S, actual S, % C | PROD + PLAN + IE | Agreed planned efficiency per line or style; learning-curve policy | Intervene today; daily review · **E** (factory today), F | M |
| P5 | **Produced (earned) minutes vs available minutes** | STD | Produced = Σ(pieces × SMV); available = manpower × working minutes [5][7][12] | As P2 | RT, same conditions | C | PROD + IE + HR | As P2 | A common unit across styles; basis for capacity and CPM · Mg | M |
| P6 | **Operator efficiency %** | STD+VAR | Minutes produced ÷ minutes worked [8]; on-standard variant [9] | Pieces per operator per operation · operator × bundle/hour | RT **only** with a scan at each workstation [16][41]; impossible with end-of-line counting | C | PROD + IE + HR | Per-operation SMV; per-operator counts | Skill matrix, incentives, line balancing · F (not executive) | M |
| P7 | **Labour / machine productivity** | STD | Output ÷ total manpower; output ÷ machines [8][14]; units per person-hour (UPPH) [29] | Output, headcount, machine register · line/factory × day | D | C | PROD + HR + MNT | A defined manpower scope. Not comparable across styles with different SMV (our reasoning) | Manpower planning, benchmarking · Mg/E (monthly) | M |
| P8 | **Capacity utilisation** | STD | Produced minutes ÷ capacity minutes × 100 [12] | Booked or produced minutes, planned capacity · factory × week/month | P | C | PLAN + ORD + IE + HR | SMV on every order | Book or decline orders, subcontract, overtime · **E** | M |
| P9 | **Machine utilisation** | STD+VAR | Running time ÷ available time [8], vs (run time per piece × pieces) ÷ available time [5] | Needle-running time (IoT) · machine × shift | RT with IoT (vendor claim [15]; idle-time sensing shown in [42]); otherwise not measurable | C | PROD + MNT | Sensor coverage | Machine investment · F | M/L |
| P10 | **Sewing OEE** | STD+VAR | Availability × performance × quality [5]. A garment "simplified" form ≈ line efficiency on QC-passed output [4] | Downtime, output, SMV, defects · line × shift | RT with IoT plus inline QC | C | PROD + QA + MNT | Downtime reason capture | Mg. Adoption in RMG is unverified; overlaps with P2 [4] | M |
| P11 | **Lost time / non-productive time (NPT) / downtime %** | PRACTICE | NPT = time lost while operators are present [9][32][33]; reason-coded capture described in [32]. A "downtime percentage" KPI is listed in [11] (formula not checked); lost ÷ available minutes is our unverified assumption | Start/stop events with reason codes · line/operator × event | RT with terminal logging [32] or machine idle sensing [42]; otherwise end of shift | Events S, % C | PROD + MNT + INV (trim shortages) | Standard reason-code list | Root-cause removal (6–10%+ gains reported [32][33]) · Mg | M |
| P12 | **Style changeover time and learning curve** | STD | Last piece of the old style to first piece of the new style, per line [11] | Line timestamps · changeover event | D (event) | C | PROD + PLAN + IE | Accurate first/last-piece timestamps | Style loading, planning · Mg | M |
| P13 | **Line balancing ratio / bottleneck** | STD+VAR | Bottleneck capacity × SMV ÷ (manpower × 60) × 100 [28], vs work content ÷ (operators × cycle time) [29]. Often **confused with line efficiency** [28] | Operation bulletin, operator allocation, per-operation output · line × style | H at best (noisy at 30 s) | C | IE + PROD | Current operator allocation | Reallocation, extra machines · F | M |
| P14 | **Man-to-machine ratio** | STD | Total manpower : sewing machines [11] | Headcount, machine register · factory × month | P | C | HR + IE | — | Overhead benchmarking · E | M |
| P15 | **Throughput / hourly output** | STD | Output ÷ time period [5] | Counts · line × hour | **RT** (same conditions as P2) | C | PROD | — | Floor pace · F; feeds E "today" view | M |

### 3.2 Quality (D2)

| ID | KPI | Status | Formula and variants | Source data · granularity | Refresh | S/C | Module | Data-quality prerequisites | Decision · audience | Conf. |
|---|---|---|---|---|---|---|---|---|---|---|
| Q1 | **DHU** (defects per hundred units) | STD | Defects ÷ pieces checked × 100. Counts *defects*, so it can exceed 100 [18][48]. The only benchmark bands found come from a vendor (≤5/10/15) and are not adopted [19] | Pieces checked and defects, by checkpoint, line, style, defect code · checkpoint × line × hour | RT with digital end-line or tablet capture; otherwise H | C | QA (+PROD) | Every checked piece counted; checkpoints kept separate; defect-code master | Quality focus, rework capacity · **E** (factory DHU), Mg | **H** (verified) |
| Q2 | **Defective %** | STD | Defective pieces ÷ checked × 100. Always ≤ DHU on the same sample (example: 30 vs 7.5) [18] | Pieces with ≥1 defect | as Q1 | C | QA | Tally sheets must record defective pieces, not only defects | Repair load · Mg | M |
| Q3 | **Rejection %** | STD+VAR | Order level: rejects ÷ **cut quantity** of the order × 100 [21]; the proxy cut − shipped [21] also captures shortages and losses (implied, not stated); stage-level rejects ÷ checked is unverified | Cut qty, rejects by stage and reason, shipped qty · order, or stage × day | P (order level); RT (stage, if scanned) | C | QA + PROD + SHIP | Reject reason coded as material vs process | Margin leakage, supplier claims · E/Mg | M |
| Q4 | **Right first time (RFT) / first-pass yield** | STD | First-time-passed ÷ total produced × 100. **Lot pass % is not RFT** [20] | Pass/fail on first presentation · line × hour/day | RT with piece-level digital capture | C | QA | Piece or bundle identity, so repaired pieces aren't counted twice | Process capability · Mg | M |
| Q5 | **Inline inspection: traffic-light status** | PRACTICE | ~10 pieces per operator every 1–2 h; red/yellow/green thresholds **vary by factory SOP** [23] | Round results · operator × round | H (data changes every 1–2 h) | Colour S, % red C | QA + HR | Current operator-to-operation mapping | Where to intervene · F | L/M |
| Q6 | **Final audit (AQL) pass rate** | STD (sampling) / PRACTICE (rate) | ISO 2859-1 sampling; apparel commonly uses AQL 2.5 major / 4.0 minor [24]. The pass-rate formula (by lots vs by quantity) is **not sourced** | Lot, sample, defect counts, result, inspector · inspection event | P/D (event) | Result S, rate C | QA + ORD + SHIP | First inspection kept separate from re-inspection | Shipment risk, buyer relationship · **E** | M |
| Q7 | **Buyer / third-party inspection failure rate** | CLIENT | Q6 restricted to buyer or third-party inspectors; not defined as a separate KPI in sources | as Q6 | P | C | QA + SHIP | Inspector type recorded | Buyer risk · E | L |
| Q8 | **Top defects (Pareto)** | PRACTICE | Defect counts by code with cumulative %; not sourced as a named KPI | Defect code × line/checkpoint · day/week | D | C | QA | Standard defect library | Training and kaizen focus · Mg | L |

### 3.3 Workforce, WIP and flow (D3)

| ID | KPI | Status | Formula and variants | Source data · granularity | Refresh | S/C | Module | Data-quality prerequisites | Decision · audience | Conf. |
|---|---|---|---|---|---|---|---|---|---|---|
| W1 | **Absenteeism %** | STD+VAR | **ILO:** unplanned leave, *including* sick and *excluding* holiday leave [26]. Vendor variant: absent days ÷ available days with approved leave removed [27]. Daily headcount variant: absent today ÷ employed (education notes, low confidence) | Roster and attendance with leave type · worker × day | D (fixed once punch-in closes) | C | **HR** (future) | Leave-type coding; roster of who is expected | Daily line manning; whole lines idle and shipments lost to absence [26] · **E**/Mg | **H** (ILO) |
| W2 | **Labour turnover %** | STD+VAR | Leavers in 12 months ÷ average employment, **workers only** [25], vs all staff (generic HR) | Join and leave dates, category · factory × month | P | C | HR | Consistent separation dates and types | Retention and training budget. Benchmark: 54.2%/yr in one Myanmar study [26] · **E** | M |
| W3 | **WIP (pieces) per section/line** | STD | Cutting = cut − sent to sewing; sewing = loaded − completed; finishing = received − packed [30][31] | Input and output counters · line × style (snapshot) | **RT** if line input and output are both scanned [16][41]; otherwise H | C | PROD | **Line input recorded**, not only output | Flow, line loading, cash tied in WIP · Mg; "WIP by factory" for E | M |
| W4 | **WIP days / throughput time** | PRACTICE | **No sourced formula.** Critical WIP = bottleneck rate × throughput time (low confidence); WIP ÷ daily output is our unverified assumption | Bundle in/out timestamps | RT/H | C | PROD | Bundle identity | Lead-time risk · Mg | L |
| W5 | **Operator utilisation** | PRACTICE | No garment formula found; (available − lost) ÷ available is our unverified assumption | As P11 | as P11 | C | PROD + IE | — | F | L |
| W6 | **Overtime %** | STD+VAR | OT hours ÷ total working hours × 100 [12] (single source); OT ÷ *regular* hours is an alternative (unverified) | Attendance/payroll hours · worker × month | D/P | C | HR | OT recorded per line | Cost and compliance · **E** | L/M |
| W7 | **Cost per minute (CPM)** | STD+VAR | (a) Total cost ÷ **produced** minutes (a health indicator; daily is recommended) [34], vs (b) cost ÷ **available** minutes (a costing rate) [35]. Labour-only vs whole-factory [35]; used for living-wage costing [54] | Payroll, overhead, produced/available minutes · factory × day/month | P | C | FIN + HR + PROD + IE | Overhead allocation key; SMV per style | Quote floor, factory health · **E** | M |

### 3.4 Executive, order and shipment (D4), mostly future modules

| ID | KPI | Status | Formula and variants | Source data · granularity | Refresh | S/C | Module | Data-quality prerequisites | Decision · audience | Conf. |
|---|---|---|---|---|---|---|---|---|---|---|
| X1 | **On-time delivery (OTD)** | STD+VAR | Orders shipped on time ÷ orders shipped in the month [11]. **Reference-date variants:** ex-factory date (the factory's own "shipment date") [37], buyer-requested date, or supplier-confirmed date [38]. **Counting unit:** order [11], PO line, or cases [39] | PO, reference date, actual ex-factory/handover date, quantity · PO line → buyer × month | P (event roll-up) | C | **SHIP + ORD** (future) | One agreed reference date per buyer; original date frozen vs revised; partial shipments handled consistently | Buyer risk, expediting · **E** | M/L |
| X2 | **On-time in-full (OTIF)** | STD+VAR | On time **and** complete quantity [38]. Retail example: Walmart thresholds and a 3% COGS fine at PO-line level [39] (secondary source, not apparel-specific) | As X1, plus ordered vs shipped quantity per PO line | P | C | SHIP + ORD | Buyer tolerance rules stored | Chargeback exposure · E | L/M |
| X3 | **Cut-to-ship ratio** | **STD+VAR (direction conflict)** | **Cut ÷ shipped, target 1 [11]; worked examples 1.01–1.02** [11][36], vs **shipped ÷ cut × 100, about 98% "very good"** [12]. These are reciprocal | Cut and shipped quantity per order · order (at closure) | P | C | PROD (cutting) + SHIP | Re-cuts handled consistently; shipped quantity reconciled to invoice | Extra-cut control (typically 2–5% [36]), leftovers · **E** | M (disputed) |
| X4 | **Order-to-ship ratio** | STD+VAR | Order qty ÷ shipped qty, target 1 [11]; the inverse is implied, not sourced | Per PO | P | C | ORD + SHIP | PO amendments versioned | Short or excess shipment · E | M |
| X5 | **Order lead time** | STD+VAR | Order placement → shipment [12]. **Start event not standardised** (PO date, confirmation, approvals). Buyer lead-time fairness is scored industry-wide [47] | PO date, ex-factory date · PO | P | C | ORD + SHIP | Fixed start event | Quoting, capacity acceptance · E | M |
| X6 | **Marker efficiency** | STD+VAR | Marker area used ÷ total marker area (80–85% acceptable) [12], vs a weight basis (snippet) | CAD markers · marker | P (event) | S (from CAD) | PROD (cutting/CAD) | CAD integration | Fabric cost · Mg | M |
| X7 | **Fabric utilisation / consumption variance** | STD+VAR | Utilisation = fabric in garments ÷ fabric available [12]. **Marked** consumption (CAD ÷ cut) vs **achieved** (fabric bought ÷ garments shipped) [12] | Store issues/returns, lays, cut and shipped qty · order (at closure) | P | C | INV + PROD + ORD | Fabric issues booked to the right order | Costing accuracy, wastage · **E** | M |
| X8 | **Capacity booking / order book** | PRACTICE | Booked SAM-minutes ÷ available minutes per future period; no sourced formula | Order SAM × quantity, capacity · factory × future week/month | P | C | PLAN + ORD + IE | SMV on every order | Accept or decline orders · **E** | L |
| X9 | **FOB value shipped / produced value** | PRACTICE | Σ shipped qty × FOB price per period (no sourced formula). "Production value" views are advertised by a vendor [43]; output × FOB or CM per piece is our assumption | Invoice qty, PO price · shipment → buyer × month | P (produced value: RT if output is scanned) | C | SHIP + FIN (+PROD) | Price versioning; discounts netted out [40] | Cash flow, buyer mix · **E** | L |
| X10 | **CM (cost of making) / margin** | STD+VAR | CM = SMV × CPM; efficiency-adjusted CM = SAM × CPM ÷ efficiency [35]. **FOB vs CM pricing models differ** [51] | Payroll, overhead, SMV · order/month | P | C | FIN + IE | As W7 | Pricing, margin per buyer · **E** | M |
| X11 | **Air-freight and late-shipment cost** | CLIENT | Factory-paid air freight plus discounts/claims for lateness [40] (source dated ~2019, stale) | Freight invoices, debit/credit notes · shipment | P | S/C | SHIP + FIN | Root-cause tag (material, production, buyer) | Cost of lateness · **E** | L/M |
| X12 | **Shipment pipeline status** | PRACTICE | POs by stage against ex-factory date [37]; a status list, not a ratio | PO stage events | D (stages change when posted) | S | SHIP + PROD + QA | Stage events posted on time | Daily expediting · E/Mg | M |
| X13 | **Buyer scorecard (composite)** | CLIENT | Brand-defined: quality, OTD, responsiveness, accuracy, price, compliance, capacity [53]; brands compute these | Brand reports | P | S (received) | ORD + QA + SHIP | Whether the factory receives brand scores is unknown | Buyer retention · E | L |

## 4. Formula and terminology variations (documented, deliberately not resolved)

Each of these needs a **client-definition decision** in Session 2. The PRD should require the platform to store the chosen definition as KPI metadata.

| # | Topic | Variant A | Variant B | Other / note |
|---|---|---|---|---|
| V1 | SMV vs SAM | Interchangeable, or SAM for planning and SMV for costing [2] | SMV excludes allowances; SAM = SMV + allowances [17] (snippet) | Time study [3] vs predetermined motion times such as GSD [10] give different values |
| V2 | Line efficiency: lost time | Overall: includes off-standard and lost time [9] | On-standard: excludes it [9][14] | Overall is used for daily line efficiency and incentives; on-standard for skill [9] |
| V3 | Line efficiency: headcount | Operators only [8] | Operators + helpers [7][14] | Ironmen sometimes added (unverified) |
| V4 | Line efficiency: output | QC-passed pieces | All stitched pieces [7] | Garment "OEE" ≈ efficiency on QC-passed output [4] |
| V5 | Factory efficiency roll-up | Weighted Σ produced ÷ Σ attended [7] | Average of line percentages (an error) [7] | **The platform must aggregate numerators and denominators, never average percentages** |
| V6 | Quality rate | DHU (defects) [18][48] | Defective % (pieces) [18] | "Defect %" is ambiguous in the field |
| V7 | Rejection base | Rejects ÷ cut qty per order [21] | Cut − shipped proxy [21] (also captures shortages, implied) | Stage-level ÷ checked (unverified) |
| V8 | RFT vs pass % | RFT on first presentation [20] | Lot pass % includes repaired pieces [20] | Not interchangeable |
| V9 | Absenteeism | ILO: unplanned incl. sick, excl. holiday [26] | Approved leave removed from denominator [27] | Daily headcount absent ÷ employed |
| V10 | Turnover population | Workers only [25] | All staff (generic HR) | Annual vs monthly |
| V11 | Line balance | Bottleneck-capacity form [28] | Work content ÷ (n × cycle time) [29] | Often confused with line efficiency [28] |
| V12 | CPM | Cost ÷ produced minutes (health) [34] | Cost ÷ available minutes (costing rate) [35] | Labour-only vs whole-factory [35] |
| V13 | **Cut-to-ship direction** | Cut ÷ shipped, target 1 [11] (examples 1.01–1.02 [11][36]) | Shipped ÷ cut × 100, about 98% [12] | The same order reads 1.02 or 98% |
| V14 | OTD reference date | Ex-factory date [37] | Buyer-requested vs supplier-confirmed [38] | Retail collect-ready vs prepaid split [39] |
| V15 | OTD counting unit | Orders per month [11] | PO lines or cases [39] | — |
| V16 | Fabric consumption | Marked (CAD ÷ cut) [12] | Achieved (bought ÷ shipped) [12] | — |
| V17 | Traffic-light thresholds | Red ≥5/10, yellow ≥2/10 [23] | Factory-specific SOPs | Not standardised |

## 5. How factories capture data, and what it means for ~30 s freshness (D5)

| Capture method | Update latency / granularity | Adoption evidence (Bangladesh / South Asia) | KPIs it makes near-real-time |
|---|---|---|---|
| Manual hourly production board | Hourly or bi-hourly at best; otherwise status arrives "after one day" [16]. Real-time systems are sold on removing the need to wait for or phone for status [41] | No prevalence figure found. Bangladesh textile and apparel Industry 4.0 maturity is reported as 1.91/5 [45] (snippet only, unverified) | None at 30 s; hourly output and efficiency |
| Bundle barcode/QR or RFID scanned at workstation terminals | Event-driven per bundle; screens update automatically after the first bundle [41]; operator-level output in real time [16] | Vendor-reported Bangladesh deployments of 776 and 524 terminals (2024–) [43] (marketing); no sector-wide percentage | Line output, achievement %, line/operator efficiency, bundle WIP, lost time [16][41]; "production value" (vendor) [43] |
| IoT machine sensors | Continuous; live pieces per hour and idle minutes at the machine [42] | Nidle at Team Group and 4A Yarn (2022–2025); Urmi Group uses "smart devices" (unspecified) [42] | Machine idle time and pieces per hour [42]; utilisation and downtime reasons per vendor claims [15] |
| Digital end-line QC (tablets) | Per inspected piece (not evidenced) | **Not evidenced** in this round | DHU, RFT (where adopted) |
| Workstation vs gate attendance | Event at login; reconciled against gate [43] | Vendor deployments [43] | Operators present (the efficiency denominator) |
| ERP/MES transactions (orders, stores, cutting, shipment) | When posted; often end of shift or day (unverified) | Integration efforts at large groups [44] | None; supports daily and period KPIs |

**Implication** (our inference from the evidence above):
- An honest ~30 s refresh is limited to sewing-floor flow KPIs: P2–P6, P9, P11, P15, W3, and Q1/Q4 where digital QC exists.
- It also requires the production module to capture events per bundle, piece or machine.
- Everything in §3.4, and most of W1/W2/W6/W7, is daily to monthly whatever the polling rate.
- Productivity growth in Bangladesh RMG of about 4.19%/yr (2014–2023) has been attributed largely to automation [46]. That source is secondary, so this is context only.

## 6. Glossary

| Term | Definition | Source |
|---|---|---|
| **SMV** | Standard minute value: standard time for an operation or garment; garment SMV = Σ operation SMVs | [1][2][3] |
| **SAM** | Standard allowed minutes. Used interchangeably with SMV, **or** as SMV + allowances (conflict, see V1) | [1][2][17] |
| **SAH** | Standard allowed hours = output × SAM ÷ 60 | [7] |
| **Basic time / rating / allowances** | Observed time × performance rating, plus personal, fatigue, machine-delay and bundle allowances; percentages are factory policy | [2][3] |
| **Time study / PMTS / GSD** | Methods for setting SMV: direct observation vs predetermined motion times (GSD uses 39 motion codes) | [3][10] |
| **IE** | Industrial engineering: sets SMVs, targets and line balance | [2][16] |
| **Produced (earned) minutes** | Pieces × SMV | [5][12] |
| **Available (attended) minutes** | Manpower × working minutes | [3][5] |
| **Overall vs on-standard efficiency** | Efficiency with vs without off-standard and lost time | [9] |
| **Off-standard time / NPT / lost time** | Attended time not spent on standard work: waiting, breakdown, power failure, line setting, missing trims | [9][32] |
| **Hourly target / planned efficiency** | 60 ÷ SAM at 100%; plans often assume about 75% | [6][13] |
| **Line balancing / bottleneck** | Levelling output across operations; the bottleneck is the lowest-capacity operation | [28][29] |
| **Learning curve / style changeover** | Efficiency ramp-up after a new style (learning-curve part unsourced); changeover = last old piece → first new piece | [11] |
| **UPPH** | Units per person-hour | [29] |
| **Man-to-machine ratio (MMR)** | Total manpower : sewing machines | [11] |
| **Helper** | Non-machine line worker; included in manpower by some sources | [7][14] |
| **Bundle / bundle ticket** | A group of cut pieces moved through sewing, identified by ticket, QR or RFID | [16][41] |
| **Shop-floor control system (SFCS)** | RFID or barcode real-time production tracking | [41] |
| **Hourly production board** | A manual board recording end-of-line output each hour | [16] |
| **WIP** | Work in progress, measured as input − output per section | [30][31] |
| **Defect / defective** | A non-conformance / a garment with ≥1 defect | [18] |
| **DHU** | Defects per hundred units = defects ÷ checked × 100 | [18][48] |
| **Reject vs alteration (rework)** | Unrepairable garment [21] vs a repaired garment returned to flow (alteration not formally defined in sources) | [21] |
| **RFT / FPY** | Right first time: passed without correction ÷ total | [20] |
| **Inline / end-line / final inspection** | In-process checking / end-of-line checking / AQL audit of a packed lot | [22][24] |
| **Traffic-light (7.0) system** | Inline sampling per operator with red/yellow/green cards | [23] |
| **AQL** | Acceptable quality limit (ISO 2859-1 sampling); apparel commonly uses 2.5 major / 4.0 minor | [24] |
| **Absenteeism (ILO)** | Unplanned leave, including sick and excluding holiday leave | [26] |
| **Labour turnover** | Leavers ÷ average employment over a period | [25] |
| **CPM** | Cost per minute (produced- or available-minute basis) | [34][35] |
| **CM / CMT** | Cost of making / cut-make-trim: the buyer supplies materials and the factory charges for conversion | [35][51] |
| **FOB** | Free on board: price including materials, labour, overhead and profit up to loading at the origin port | [51] |
| **Ex-factory date** | Date the goods must leave the factory for the forwarder; "to the factory, the shipment date" | [37] |
| **TNA (time and action calendar)** | Backward-planned order calendar from sampling to shipment, with planned vs actual dates and owners | [49][50] |
| **PCD** | Planned cut date: when bulk cutting is scheduled to start; with ex-factory, one of the two most critical TNA dates | [49] |
| **Extra cut %** | Cut allowance above order quantity, typically 2–5% | [36] |
| **Cut-to-ship / order-to-ship** | See X3, X4 and V13 | [11][12][36] |
| **OTD / OTIF** | On-time delivery / on-time in-full | [11][38][39] |
| **Marker efficiency** | Marker area used by pattern pieces ÷ marker area | [12] |
| **Marked vs achieved consumption** | CAD consumption vs fabric bought ÷ garments shipped | [12] |
| **Air shipment / discount** | Factory-paid air freight and deductions on export receipts for lateness | [40] |
| **PP sample, size set, merchandiser, ETD/ETA, consolidation** | Common trade terms, **not sourced this round** (unverified) | — |

## 7. Cross-dimension insights

1. **The executive audience and the 30 s target point at different KPIs.** The confirmed primary users are executives and CXOs. The KPIs they care about most in the trade literature (OTD, cut-to-ship, CPM/CM, capacity booking, turnover, AQL pass rate) are period or event metrics (§3.4, §5). The 30 s target is valuable for a **"factory today" executive view**: output vs target, efficiency so far, WIP and downtime by factory and line. It is not valuable for the executive scorecard. This argues for a **per-KPI freshness setting**, which matches the open brief question about where freshness is configured.
2. **The production module's capture method decides whether "live" is real.** If it records hourly counts, every RT-class KPI is effectively hourly. If it records per bundle or per piece, RT is feasible. This is the single most important data-reality question for Session 2.
3. **Master data is the hidden dependency.** P2–P8, W7, X8 and X10 all need an SMV/SAM per style, and most need attendance per line. Several "production" KPIs therefore depend on IE and HR data. Those may not exist yet, or may live in future modules.
4. **Correct aggregation is part of the definition.** Factory efficiency must sum numerators and denominators, not average percentages (V5). The same applies to DHU and other ratios rolled up from line to factory to company. A generic dashboard that averages percentages would produce wrong executive numbers. This is a PRD requirement for the metric engine.
5. **With no historical data, trends start at go-live.** Monthly and seasonal KPIs (§3.4, W2, W7) need months of accumulated events before they are meaningful. This reinforces the brief's open question about capturing history from go-live.

## 8. Recommendations

| # | Recommendation | Feeds | Confidence basis |
|---|---|---|---|
| R1 | Take §3 to Session 2 as a **ranking sheet**: the client picks a top 10 and marks each "needed live (~30 s)" or "daily/period" | Brief §9 Q2; PRD KPI scope | High: catalogue grounded in sources; ranking is the client's |
| R2 | For each chosen STD+VAR KPI, have the client **select a variant** (V1–V17). For PRACTICE and CLIENT KPIs, have them **supply the formula** | PRD: KPI definition requirements | High: variation documented by independent publishers |
| R3 | PRD requirement: KPI definitions are **versioned metadata** that hold the client's chosen formula, unit, direction (higher or lower is better), target, aggregation rule (Σnum ÷ Σden) and freshness class | PRD functional requirements; architecture (metric layer) | High (V5, V13 evidence) |
| R4 | PRD requirement: **freshness is configurable per KPI or widget**, with RT used only where the source captures events at that rate. The dashboard shows "data as of" time, not just "refreshed at" time | PRD real-time NFRs | Medium: inference from §5 |
| R5 | Session 2 must establish the production module's capture method (hourly board vs bundle/piece scan vs IoT) and whether **SMV per style** and **attendance per line** exist | Brief §9 Q1; PRD data dependencies | High: §5, §7 |
| R6 | Map each chosen KPI to its module (current PROD vs future QA/HR/ORD/SHIP/INV/FIN). Mark KPIs whose module doesn't exist as **post-MVP or "on module availability"** | PRD MVP scope; epics sequencing | Medium |
| R7 | Use the §6 glossary as the PRD's domain vocabulary. Mark the unsourced trade terms for client confirmation | PRD glossary | Medium (practitioner-heavy sources) |

## 9. Open questions

| Question | What it would take |
|---|---|
| Which definitions does **this client** use today for efficiency, DHU, cut-to-ship and OTD? | Session 2: ask for one real report of each |
| Does the production module capture per bundle or piece, or hourly? Does it hold SMV and attendance? | Session 2 data-reality questions; read the production module's schema |
| What do listed RMG makers actually report to boards (annual-report KPIs)? | Deepen: Gokaldas, Hela, Teejay annual reports (blocked or not reached this round) |
| BGMEA/BKMEA or ILO Better Work KPI norms for Bangladesh? | Deepen with Better Work Bangladesh research briefs and BGMEA Centre of Innovation material |
| Peer-reviewed line-balancing and efficiency formulas (Bongomin et al. 2020; AUTEX 2019) | Deepen via ResearchGate/PMC mirrors (publisher sites returned 403) |
| Finishing and cutting productivity KPIs; NPT % and operator-utilisation formulas; AQL pass-rate basis | Deepen, or client definition (R2) |
| How common manual boards vs scanning vs IoT are in Bangladesh (2025–26) | Deepen; Heliyon 2024 full text [45] |
| Primary ISO 2859-1 tables; Walmart and apparel-brand OTIF manuals | Deepen with primary documents |

## 10. Source appendix

Accessed 2026-10-02 for all rows. "OCS" = Online Clothing Study (one practitioner publisher; agreement among blogs that copy it is not independent).

| # | Supports | Publisher | Pub date | Accessed | Confidence |
|---|---|---|---|---|---|
| 1 | SAM/SMV definitions | [OCS](https://www.onlineclothingstudy.com/2012/05/full-form-of-sam-and-smv-in-apparel.html) | 2012-05 | 2026-10-02 | M |
| 2 | SAM = SMV interchangeability; time-study formula; allowances are policy | [Textile Learner](https://textilelearner.net/sam-and-smv-calculation-in-garment-industry/) | 2026-07 (shown) | 2026-10-02 | M |
| 3 | Efficiency, capacity, SMV formulas | [Garments Doctor](https://garmentsdoctor.com/ie-formula-in-garments-industry/) | 2023-10 | 2026-10-02 | M |
| 4 | Garment OEE simplified | [OCS](https://www.onlineclothingstudy.com/2024/11/how-to-calculate-oee-in-garment.html) | 2024-11 | 2026-10-02 | M |
| 5 | Efficiency alternatives, machine utilisation, OEE, throughput | [Apparel Resources](https://apparelresources.com/business-news/manufacturing/performance-measurement-tools-2-operations-quality/) | 2012-08 | 2026-10-02 | M |
| 6 | Efficiency formula; hourly target | [OCS](https://www.onlineclothingstudy.com/2017/01/efficiency-formula-calculate-operator.html) | 2017-01 | 2026-10-02 | H (verified) |
| 7 | Factory efficiency weighting; helpers; output definition | [OCS](https://www.onlineclothingstudy.com/2022/01/how-to-calculate-daily-factory.html) | 2022-01 | 2026-10-02 | M |
| 8 | Production formulas: target, productivity, utilisation | [OCS](https://www.onlineclothingstudy.com/2016/11/10-formulas-for-production-calculation.html) | 2016-11 / 2025-01 | 2026-10-02 | M |
| 9 | Overall vs on-standard efficiency | [OCS](https://www.onlineclothingstudy.com/2019/03/overall-efficiency-vs-on-standard.html) | 2019-03 | 2026-10-02 | H (verified) |
| 10 | GSD / PMTS | [Coats Digital](https://www.coatsdigital.com/en/manufacturer/gsdcost/) (vendor) | n.d. | 2026-10-02 | M |
| 11 | KPI list: factory efficiency, cut-to-ship (cut÷ship), order-to-ship, OTD, changeover, MMR | [OCS](https://www.onlineclothingstudy.com/2012/05/kpis-for-garment-manufacturers.html) | 2012-05 / 2024-09 | 2026-10-02 | M |
| 12 | Cut-to-ship (ship÷cut); capacity utilisation; PPI; OT %; marker efficiency; consumption; lead time | [Apparel Resources](https://apparelresources.com/business-news/manufacturing/performance-measurement-tools-3-cutting-production-planning/) | 2012-09 | 2026-10-02 | M |
| 13 | Planning at ~75% efficiency | [OCS](https://www.onlineclothingstudy.com/2021/08/why-do-garment-manufacturers-plan.html) | 2021-08 | 2026-10-02 | M |
| 14 | Bangladesh case: efficiency with helpers; on-standard | [Textile Blog (DIU case)](https://www.textileblog.com/efficiency-and-productivity-of-sewing-line/) | 2020 (data) | 2026-10-02 | M/H |
| 15 | IoT machine monitoring | [ProCom Automation](https://procom-automation.com/en/garment-production-monitoring-system-real-time-insights-for-sewing-operations/) (vendor) | 2026-01 | 2026-10-02 | L/M |
| 16 | Manual hourly boards; RFID per-operator real-time output | [OCS](https://www.onlineclothingstudy.com/2019/11/rfid-system-for-production-tracking-in.html) | 2019-11 | 2026-10-02 | M |
| 17 | SMV excludes allowances; SAM includes them (snippet) | [Textile and Apparel Insights](https://textileandapparelinsights.com/differences-between-sam-and-smv-in-garment-manufacturing/) | n.d. | 2026-10-02 | L |
| 18 | DHU; defective %; worked example | [OCS](https://onlineclothingstudy.com/what-are-defect-and-defective-pieces/) | 2024-10 | 2026-10-02 | H (verified) |
| 19 | DHU benchmark bands (vendor; not used as a benchmark) | [ScanERP](https://scanerp.pro/blog/dhu-benchmarks-garment-factory-2026.html) (vendor) | 2026 | 2026-10-02 | L |
| 20 | RFT; lot pass % ≠ RFT | [OCS](https://www.onlineclothingstudy.com/2019/08/what-is-right-first-time-quality-in.html) | 2019-08 | 2026-10-02 | M |
| 21 | Rejection % on cut quantity; reject definition | [OCS](https://www.onlineclothingstudy.com/2012/09/how-to-control-garment-rejection-rate.html) | 2012-09 | 2026-10-02 | M |
| 22 | Inline inspection | [OCS](https://www.onlineclothingstudy.com/2015/03/what-is-inline-inspection-in-garment.html) | 2015-03 | 2026-10-02 | M |
| 23 | Traffic-light system thresholds | [Textile Learner](https://textilelearner.net/traffic-light-system-in-garment-industry/) | n.d. | 2026-10-02 | L/M |
| 24 | AQL / ISO 2859-1 apparel defaults | [AQI Service](https://aqiservice.com/acceptable-quality-level-aql-in-garment-inspection/) | n.d. | 2026-10-02 | M |
| 25 | Labour turnover (workers only) | [OCS](https://www.onlineclothingstudy.com/2012/07/how-to-calculate-labour-turnover-rate.html) | 2012-07 | 2026-10-02 | M |
| 26 | ILO absenteeism definition; 54.2% turnover; absence impacts | [ILO (Yangon), *Skilled Workers Matter*](https://www.ilo.org/sites/default/files/wcmsp5/groups/public/%40asia/%40ro-bangkok/%40ilo-yangon/documents/publication/wcms_736628.pdf) | 2020-02 | 2026-10-02 | H |
| 27 | Absenteeism variant with approved leave removed | [Yourco](https://www.yourco.io/blog/calculate-absenteeism-percentage-manufacturing) (vendor) | 2026 | 2026-10-02 | L |
| 28 | Line balancing rate formula | [Garments Doctor](https://garmentsdoctor.com/line-balancing-formula/) | 2024-10 | 2026-10-02 | M |
| 29 | Line balancing model; UPPH | [Kong et al., arXiv](https://arxiv.org/pdf/2502.00455) (preprint) | 2025-02 | 2026-10-02 | M |
| 30 | WIP per section | [OCS](https://www.onlineclothingstudy.com/2012/10/how-to-calculate-wip-level-in-cutting.html) | 2012-10 | 2026-10-02 | M |
| 31 | WIP per section incl. washing | [Textile Learner](https://textilelearner.net/how-to-reduce-wip-in-garment-manufacturing-industry/) | n.d. (inconsistent) | 2026-10-02 | M |
| 32 | NPT definition; real-time lost-time capture; >10% gain | [OCS](https://www.onlineclothingstudy.com/2016/09/why-to-measure-non-productive-time.html) | 2016-09 | 2026-10-02 | M |
| 33 | NPT causes; 6–10% gains | [Textile Learner](https://textilelearner.net/non-productive-time-in-the-garment-industry-causes-impact-and-solutions/) | 2025 | 2026-10-02 | L/M |
| 34 | CPM on produced minutes (health indicator) | [OCS](https://www.onlineclothingstudy.com/2023/05/check-health-of-garment-factory-by.html) | 2023-05 | 2026-10-02 | M |
| 35 | CPM on available minutes; CM = SMV × CPM | [OCS](https://www.onlineclothingstudy.com/2013/06/method-of-calculating-cost-per-minute.html) | 2013-06 | 2026-10-02 | M |
| 36 | Cut-to-ship (cut÷ship, 1.02); extra cut 2–5% | [OCS](https://www.onlineclothingstudy.com/2013/10/what-is-cut-to-ship-ratio-in-apparel.html) | 2013-10 | 2026-10-02 | M |
| 37 | Ex-factory date | [OCS](https://www.onlineclothingstudy.com/2018/01/what-is-ex-factory-date.html) | 2018-01 | 2026-10-02 | M |
| 38 | OTD reference dates; OTIF definition | [TradeBeyond](https://www.tradebeyond.com/industry-hub/supplier-scorecard-template) (vendor, snippet) | n.d. | 2026-10-02 | L/M |
| 39 | Walmart OTIF thresholds, 3% fine, PO-line level | [8th & Walton](https://www.8thandwalton.com/blog/walmart-otif) (secondary) | n.d. | 2026-10-02 | L/M |
| 40 | Air shipment and discounts on late shipments (Bangladesh) | [The Financial Express (BD)](https://thefinancialexpress.com.bd/trade/rmg-exporters-face-costly-air-shipment-discount-on-bills-1577335495) | ~2019-12 (stale) | 2026-10-02 | M (stale) |
| 41 | RFID shop-floor control; auto-updating screens | [OCS](https://www.onlineclothingstudy.com/2017/09/shop-floor-control-system-in-apparel.html) | 2017-09 | 2026-10-02 | M |
| 42 | IoT (Nidle) at Team Group and 4A Yarn; Urmi Group "smart devices" | [Rest of World](https://restofworld.org/2025/bangladesh-garment-factories-automation-surveillance/) | 2025-03 | 2026-10-02 | M/H |
| 43 | Bangladesh RFID/NFC deployments; attendance reconciliation; "production value" views | [GoTEE](https://gotee-bd.com/) (vendor) | n.d. (2024–) | 2026-10-02 | L/M |
| 44 | Automation and system integration in Bangladesh | [The Interline](https://www.theinterline.com/2025/05/06/rebooting-bangladesh-inside-the-automation-wave-redefining-a-global-textile-powerhouse/) | 2025-05 | 2026-10-02 | M |
| 45 | Industry 4.0 maturity 1.91/5 (snippet) | [Heliyon (Elsevier)](https://www.sciencedirect.com/science/article/pii/S2405844024080757) | 2024 | 2026-10-02 | M (snippet) |
| 46 | 4.19%/yr productivity growth, BIDS study | [Apparel Resources](https://apparelresources.com/business-news/manufacturing/automation-drives-productivity-gains-bangladeshs-garment-sector-bids-study-finds/) | ~2025 | 2026-10-02 | L/M |
| 47 | Better Buying purchasing-practices index 2025 (lead-time fairness context) | [Cascale](https://cascale.org/resources/press-news/press-releases/better-buying-purchasing-practices-index-2025-reveals-industry-resilience-despite-disruption/) | 2025-11 | 2026-10-02 | M |
| 48 | DHU formula (independent check) | [Advance Textile](https://www.advancetextile.net/2025/04/defects-per-hundred-units-dhu-overview.html) | 2025-04 | 2026-10-02 | M |
| 49 | TNA definition; PCD | [Textile Learner](https://textilelearner.net/time-and-action-plan-for-garment-merchandising/) | n.d. | 2026-10-02 | M |
| 50 | TNA in apparel merchandising (independent check) | [Apparel Resources](https://apparelresources.com/business-news/manufacturing/time-action-calendar-apparel-merchandising-limitations-conventional-approach-tna/) | n.d. | 2026-10-02 | M |
| 51 | FOB vs CM/CMT pricing models | [Capital World Group](https://capitalworldgroup.com/fob-vs-cm-in-apparel-manufacturing/) | n.d. | 2026-10-02 | M |
| 52 | Line efficiency formula (independent check) | [Textile Calculator](https://textilecalculator.com/line-efficiency-calculator/) | n.d. | 2026-10-02 | M |
| 53 | Apparel vendor scorecard components | [Fabrikn](https://www.fabrikn.com/blog/build-vendor-scorecard-apparel-manufacturing-suppliers/) (vendor blog, snippet) | n.d. | 2026-10-02 | L |
| 54 | Labour minute costing (living wage) | [Fair Wear Foundation](https://wp.fairwear.org/wp-content/uploads/2020/12/FWF-LabourMinuteCosting.pdf) (title only) | 2020-12 | 2026-10-02 | L |

## 11. Staleness map

Computed with `recon_kit.py staleness` (today 2026-10-02). Windows come from the domain pack:
- definitions and structure: 36 months
- quantitative figures: 36 months
- technology adoption: 18 months
- gatekeeper/retailer policy: 18 months

Undated sources were given approximate dates (marked ≈).

| Claim | Class | Pub date | Re-check by | Stale? |
|---|---|---|---|---|
| [6] Line efficiency formula | definition | 2017-01 | 2020-01 | yes* |
| [18] DHU formula | definition | 2024-10 | 2027-10 | no |
| [11]/[12] Cut-to-ship direction conflict | definition | 2012-09 | 2015-09 | yes* |
| [26] ILO absenteeism definition | definition | 2020-02 | 2023-02 | yes* |
| [26] 54.2% turnover (Myanmar) | quantitative | 2020-02 | 2023-02 | **yes** |
| [41] Bundle RFID scanning gives real-time data | tech-adoption | 2017-09 | 2019-03 | **yes** |
| [42] IoT sensors (Nidle) at Team Group, 4A Yarn | tech-adoption | 2025-03 | 2026-09 | **yes (just lapsed)** |
| [43] Vendor RFID deployments in Bangladesh | tech-adoption | ≈2024 | 2025-07 | **yes** |
| [45] Industry 4.0 maturity 1.91/5 | tech-adoption | ≈2024 | 2025-07 | **yes** |
| [46] 4.19%/yr productivity growth | quantitative | ≈2025 | 2028-01 | no |
| [39] Walmart OTIF thresholds | player-policy | ≈2025 | 2026-07 | **yes** |
| [40] Air-shipment/discount practice | structure | 2019-12 | 2022-12 | **yes** |

**10 of 12 claims are past their re-check date. The earliest re-check date was 2015-09** (the cut-to-ship definitions).

\* KPI *definitions* are slow-moving trade conventions. Their age is less of a problem than the technology-adoption and policy claims, but definitions should still be re-confirmed against the client's own practice in Session 2. The claims that genuinely need refreshing are the **adoption claims** ([41][43][45]). They underpin the "~30 s is only feasible with scanning or IoT" finding, so ask about the client's own capture method directly (R5) rather than relying on the sector picture.
