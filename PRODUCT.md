# Product

## Register

product

## Users

Internal operations team at Foodics or Daftra who manage customer integrations. They open the dashboard to verify sync health, troubleshoot failed invoices or products, and configure client mappings. They use this alongside Daftra and Foodics admin panels, often in a support context where speed of diagnosis matters more than exploration.

## Product Purpose

A sync bridge between Foodics (restaurant POS) and Daftra (accounting). Automatically pushes invoices and products from Foodics into Daftra, surfaces sync status and failure reasons, and lets ops staff retry failed syncs and configure default client/branch mappings. Success means the ops person never has to open a spreadsheet or manually reconcile.

## Brand Personality

Fast, direct, reliable. The interface should feel like a well-calibrated instrument: no decoration, no guesswork. Every element answers a question the user has right now. Copy is terse and specific (invoice counts, sync timestamps, error strings). No cheer, no celebration, no personality for its own sake.

## Anti-references

No specific anti-references, but the direction is clear: avoid anything that feels like enterprise bloat (heavy tables, nested menus, SAP-style navigation) or generic SaaS (empty metric cards, placeholder graphs, onboarding tours).

## Design Principles

1. **Status at a glance.** The most important information is whether things are syncing. That answer should be visible before any scrolling or clicking.
2. **Errors are actionable.** When something failed, show what failed and how to fix it, not just that it failed. Error messages are specific enough to paste into a support ticket.
3. **No unnecessary depth.** Flat navigation. Every page is one click from the sidebar. The tool is small; the structure should reflect that.
4. **Bilingual by default.** Arabic and English are first-class citizens. Layouts must work in both LTR and RTL without visual compromise.
5. **Quiet competence.** The interface does its job and gets out of the way. No onboarding, no tooltips explaining obvious things, no celebratory animations.

## Accessibility & Inclusion

WCAG 2.1 AA minimum. The ops team works in varied environments (office, mobile on the go). Respect `prefers-reduced-motion`. Ensure sufficient contrast in both light and dark modes. All interactive elements must be keyboard-navigable. Bilingual RTL/LTR support is functional, not an afterthought.
