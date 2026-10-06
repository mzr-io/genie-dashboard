---
title: "Editorial review: PRD Addendum (RMG Dynamic Dashboard Platform)"
target: addendum.md
created: 2026-10-02
lenses: structure, then prose
style_guide: Microsoft Writing Style Guide
reader_type: humans (architects, delivery team); also consumed by downstream AI workflows
---

# Editorial review: PRD addendum

**Purpose read.** This document exists to help architects and the delivery team (and the `bmad-architecture`, `bmad-ux` and `bmad-create-epics-and-stories` workflows) see the proposed technical posture, the open options, and the decision criteria that sit behind the PRD, without mistaking any of it for a final decision.

**Structure model.** Strategic/Context (Pyramid), with Reference/Database traits: sections are lettered and cited from the PRD (§J, §I and others), so readers jump to them directly. Section order and letters are fixed by those references, so no row proposes reordering sections.

**Word metrics** (from `word_metrics.py`): 1,806 words in total. §J holds 694 (38%), §A 168, §B 143, §D 113, §I 108, §C 100, the title block 93, and the rest fall below 60 each.

**Voice and style notes to preserve.** The voice is terse, declarative, and decision-record-like. Bold carries the key claim in each bullet. Status tags are inline. Lists end in semicolons. Spelling is British ("behaviour", "modelling"), which matches the PRD. The doc omits serial commas throughout. Rows P16 and P17 raise the last two against the Microsoft guide as JUDGMENT only.

**Cross-reference check.** All FR, NFR, PRD §, O, research §5 and brief addendum D4 references resolve. No reference is broken. Two are **inconsistent** with the PRD (rows S5 and S6). One claim has no anchor (S7).

**Status key.** SAFE means a mechanical fix that preserves meaning. JUDGMENT means the author must decide.

## Findings

| # | Pass | Original Text | Revised Text | Changes | Status |
|---|---|---|---|---|---|
| S1 | structure | Title block: the **Neutrality note** callout (line 10) comes before the purpose paragraph "This addendum holds technical posture…" (line 12), and both say "not a final decision" (93 words) | MOVE the purpose paragraph above the Neutrality note, then MERGE "**Nothing here is a final decision.**" into it, so the callout doesn't repeat it. Keep the callout and its wording. | Readers learn what the doc is before they hit the caveat about §J. This removes one restatement (saves ~6 words). Keeps the callout's visual weight. | JUDGMENT |
| S2 | structure | Whole document: the tags [PROPOSED], [ADR], [CLOSED], [DIRECTION …] and CONFIRMED are used but never defined here | QUESTION: add a one-line legend to the purpose paragraph, for example "Tags: PROPOSED = recommended default; ADR = decided in an architecture decision record; CLOSED = settled for MVP; DIRECTION = delivery-team steer; CONFIRMED = client-confirmed." (Tags unchanged.) | Missing scaffolding. Downstream AI workflows and new readers must infer what each tag means. Adds ~30 words. | JUDGMENT |
| S3 | structure | §J sits last but carries 38% of the words and the one mandatory evaluation | PRESERVE the position. The PRD cites the letters, and the Neutrality note already points readers to §J up front. | Buried critical content is already offset by the pointer in the first callout. Reordering would break the letters. | JUDGMENT |
| S4 | structure | §J "**Client acceptance.** If an embedded or hybrid option is selected…" (31 words), placed between the criteria table and "Inputs the evaluation depends on" | MOVE it to follow "**Expected output:** …". | It's a follow-up that applies only after the decision. Where it sits now, it breaks the flow from criteria to inputs to output. 0 words. | JUDGMENT |
| S5 | structure | §J "**Inputs the evaluation depends on:** O1, O6, O7, O9, O11, O19" | QUESTION: the PRD §13 phase-gating row for `bmad-architecture (incl. build-vs-embed, addendum §J)` lists **O1, O7, O9, O11, O19, O24**. That row includes O24 and omits O6, so the two lists disagree. Reconcile one list or the other. The O references in this doc are unchanged. | Cross-reference inconsistency. An architect reading either doc gets a different blocker set. | JUDGMENT |
| S6 | structure | §J criteria table, Scalability row: "NFR-2 to NFR-5 (TBD, O9)" | QUESTION: in PRD §13, O9 affects **NFR-2 to NFR-4**. NFR-5 (source protection) is "TBD with client IT", not O9. Confirm which range is intended. | Cross-reference inconsistency. | JUDGMENT |
| S7 | structure | §A "It contradicts a CONFIRMED principle." | QUESTION: name the principle and its source, for example "It contradicts the CONFIRMED principle 'metadata-driven; no table per component or KPI' (brief §8)." | It's an unanchored reference, and the reader can't verify it. Adds ~10 words. | JUDGMENT |
| S8 | structure | §J criteria table, rows "Dataset configuration (§4.3)" and "Snapshots and history (§4.11)" | QUESTION: other rows cite FRs, but these two cite only sections. Should they cite FR-12 to FR-14 and FR-54 to FR-57, as the rest of the table does? (Existing references unchanged.) | The table's schema is inconsistent, and the epics stage uses FR IDs as anchors (§K). | JUDGMENT |
| S9 | structure | §J criteria table, column "Question for each option". Six rows hold noun phrases rather than questions (Dashboard customization, Governance, Scalability, Extensibility, Cost, Time to MVP). | Rename the column header to "What to assess for each option". Cell content is unchanged. | Makes the column header match the cells without touching the criteria. 0 words. | JUDGMENT |
| S10 | structure | §J "**Ad-hoc BI boundary.** General-purpose ad-hoc BI (…) is **not an MVP requirement**." Positioning already says "General-purpose ad-hoc BI stays outside the MVP." | CONDENSE the opener to: "**Ad-hoc BI boundary.** General-purpose ad-hoc BI (free-form exploration, unrestricted SQL, a full analyst workspace) stays outside the MVP, as stated above, and is **not** raised with the client at this stage." | Merges two sentences. The restatement stays as reinforcement, since the boundary list needs it as a premise. Saves ~6 words. | JUDGMENT |
| S11 | structure | §A concept list and §I candidate-module table | PRESERVE both. | They look cuttable, but they're the scannable anchors that architecture and the epics stage will map to. | SAFE |
| S12 | structure | §K Traceability note (54 words) | PRESERVE it. | It tells downstream workflows where the trace chain and the ADRs are produced. That's useful orientation for both kinds of reader. | SAFE |
| P1 | prose | "The grammar is parsed into an abstract syntax tree (AST), type-checked and compiled. It is never assembled by string concatenation." (§B) | "Each formula is parsed into an abstract syntax tree (AST), type-checked and compiled. It is never assembled by string concatenation." | Wrong subject. A formula is parsed, not the grammar, and "It" now points to the formula. | SAFE |
| P2 | prose | "(e.g. PostgreSQL JSONB)" (§A); "a charting library (e.g. ECharts)" (§I) | "(for example, PostgreSQL JSONB)"; "a charting library (for example, ECharts)" | The Microsoft guide says to avoid "e.g.". §F already uses "for example". | SAFE |
| P3 | prose | "*EAV storage for fact data.*" (§A) | "*Entity-attribute-value (EAV) storage for fact data.*" | Spells out the acronym at first use. | SAFE |
| P4 | prose | "Items marked [ADR] are decided in `bmad-architecture`." (intro); "Positioning … the product is…" (§J), first use of "BI" in the body | "Items marked [ADR] are decided in `bmad-architecture` as architecture decision records (ADRs)." First use of BI: "business intelligence (BI)". The tag and the heading are unchanged. | Spells out acronyms at first use. ADR is currently expanded only in §K, and BI is never expanded. | SAFE |
| P5 | prose | "SSO (O19)" (§J criteria table, Governance row) | "single sign-on (SSO) (O19)" | Spells out the acronym at first use. It's expanded only later, in the Inputs list. | SAFE |
| P6 | prose | "Replica lag counts toward ~30 s" (§C table) | "Replica lag counts toward the ~30 s target" | Supplies the missing noun. | SAFE |
| P7 | prose | "At a ~30 s target, the candidates are:" (§D) | "For the ~30 s target, the candidates are:" | Fixes the preposition. The target is a known, fixed value. | SAFE |
| P8 | prose | "Acceptable only isolated and limited (client)" (§C table) | Consider: "Acceptable only if isolated and limited (client position)"? | Supplies the missing "if". It's unclear what "(client)" refers to. | JUDGMENT |
| P9 | prose | "compute the numerator and denominator per group, then roll up by summing (PRD FR-17)" (§B) | Consider: "compute the numerator and denominator per group, then roll up by summing numerators and denominators separately (PRD FR-17)"? | The object of "summing" is missing. Matches the wording in the §J criteria row ("roll up numerator and denominator separately"). | JUDGMENT |
| P10 | prose | "That points to a grid-layout library…" (§F) | "These needs point to a grid-layout library…" | Gives "That" a clear antecedent. | SAFE |
| P11 | prose | §G list: "**configuration schema,** which drives…"; "**renderer;**"; "**live-update behaviour.**" (other items use "**label:** …") | "**configuration schema:** drives the generated configuration form;", "**renderer**;", "**live-update behaviour**." | Makes the label punctuation consistent and moves the punctuation outside the bold. | SAFE |
| P12 | prose | "Each is derived from a confirmed or PRD requirement." (§J) | "Each is derived from a confirmed client requirement or a PRD requirement." | Removes the ambiguous elliptical modifier. | SAFE |
| P13 | prose | "It is built on the preferred stack (addendum §I)." (§J, option 1); also "(addendum §I)" is a self-reference | Consider: "It would be built on the preferred stack (§I)."? | This is an option, not a decision, so the conditional fits the doc's neutrality. Inside the addendum itself, "addendum §I" reads oddly. | JUDGMENT |
| P14 | prose | "Raise this only then." (§J, Client acceptance) | "Raise this with the client only at that point." | Gives the clipped adverb a clear referent. | SAFE |
| P15 | prose | "the evaluation shows an option provides it" (§J, Ad-hoc BI boundary) | "the evaluation shows that an option provides it" | Adds "that" so the reader doesn't misparse "shows an option". | SAFE |
| P16 | prose | Throughout, for example "options and rationale", "governance, ratio-safe roll-ups, Unavailable state, Snapshots, Data Scope", "Version, Permission and Audit" | Consider: add serial (Oxford) commas throughout? | The Microsoft guide requires the serial comma. The doc and the PRD consistently omit it, so apply it across both or neither. | JUDGMENT |
| P17 | prose | "behaviour" (§G), "modelling" (§J) | Consider: "behavior", "modeling"? | The Microsoft guide uses US spelling. The PRD uses British spelling ("behaviour", "personalise", "colour"), so keep both docs consistent. | JUDGMENT |
| P18 | prose | "It is **not** raised with the client at this stage." (§J) | Consider: "Do **not** raise it with the client at this stage."? | Changes passive to active, as the Microsoft guide prefers. It also changes a statement into a directive, so the author should confirm that's intended. If S10 is accepted, it covers this sentence. | JUDGMENT |
| P19 | prose | "Build effort vs integration effort" (§J table) | "Build effort versus integration effort" | The Microsoft guide spells out "versus" in body text. Headings are unchanged. | SAFE |
| P20 | prose | "each to be justified in architecture against these requirements" (§I) | "each to be justified in `bmad-architecture` against these requirements" | Names the stage the same way as the rest of the doc. | SAFE |
| P21 | prose | "as the original requirements asked" (§A) | Consider: "as the original requirements asked (brief addendum §A)"? | The source isn't anchored, so readers can't trace it. | JUDGMENT |

## Summary

- **Recommendations:** 33 rows. 12 are structure (3 PRESERVE, 2 MOVE, 1 MERGE folded into S1, 1 CONDENSE, 6 QUESTION) and 21 are prose. 16 are SAFE and 17 are JUDGMENT.
- **Estimated length change if all are accepted:** about +40 words net. Cuts save ~12 words (S1 and S10). Additions run about +52 words (the S2 legend, the S7 anchor, the acronym expansions, and the clarifying nouns). The result is about 1,846 words, or +2% on 1,806. No length target was given.
- **Comprehension trade-offs:** none. No row cuts a visual aid, summary, or example. The only net growth is scaffolding: the tag legend, the acronym expansions, and the source anchors.
- **Cross-references:** no reference is broken. Two are inconsistent with the PRD (S5 and S6), one CONFIRMED claim has no anchor (S7), and two criteria rows lack FR anchors (S8).
