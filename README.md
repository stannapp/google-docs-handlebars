# Google Docs Handlebars

Renders a Handlebars-subset template **inside a Google Doc, in place**, through
the Google Docs API.

Text template engines produce a string. This one produces a plan of
`documents.batchUpdate` operations: table rows are duplicated for loops,
discarded blocks are deleted, and every `{{tag}}` is replaced — while the
document keeps its layout, styles, headers and footers. The document itself
(usually a fresh copy of a template document) is the rendering target.

The package has **no dependency** — not even on an HTTP client: you hand it a
tiny `DocumentClient` adapter and it never talks to the network itself.

## Installation

```bash
composer require stann/google-docs-handlebars
```

## Quick start

```php
use Stann\GoogleDocsTemplate\Client\DocumentClient;
use Stann\GoogleDocsTemplate\TemplateEngine;

// 1. Adapt your Google API access (PSR-18, SDK, fake in tests...).
final class MyDocumentClient implements DocumentClient
{
    // GET https://docs.googleapis.com/v1/documents/{id}, JSON-decoded.
    public function getDocument(string $documentId): array { /* ... */ }

    // POST https://docs.googleapis.com/v1/documents/{id}:batchUpdate
    public function batchUpdate(string $documentId, array $requests): void { /* ... */ }
}

// 2. Render against display-ready values.
(new TemplateEngine())->render(new MyDocumentClient(), $documentId, [
    'date' => '10/07/2026',
    'customer' => [
        'name' => 'Syndic des Lilas',
        'address' => '3 rue des Lilas, 75020 Paris',
    ],
    'quotation' => [
        'reference' => '20260701',
        'totalAmount' => '1 234,50 €',
    ],
    'items' => [
        ['name' => 'Dératisation', 'quantity' => '2', 'totalAmount' => '590,00 €'],
        ['name' => 'Désinsectisation', 'quantity' => '1', 'totalAmount' => '644,50 €'],
    ],
]);
```

Values must be **display-ready strings**: the engine never formats numbers,
dates or currencies — that is the integrator's concern.

## Template syntax

The grammar is a strict subset of Handlebars. Templates stay portable: what
renders here renders identically under handlebars.js.

| Construct | Example | Notes |
|---|---|---|
| Variable | `{{customer.name}}` | Dotted paths into the context. |
| Comment | `{{!-- draft note --}}` / `{{! note }}` | Removed from the output. |
| Condition | `{{#if quotation.reference}} ... {{/if}}` | Keeps or deletes the block. |
| Negated condition | `{{#unless customer.address}} ... {{/unless}}` | Inverse of `#if`. |
| Loop | `{{#each items}} ... {{/each}}` | On a **table row** — see below. |
| Loop item | `{{item.name}}`, `{{item.totalAmount}}` | Current row of the loop. |
| Loop index | `{{@index}}` | Zero-based position. |
| Helper | `{{date "DD/MM/YYYY" quotation.dueAt}}` | Registered at construction — see below. |

Truthiness follows Handlebars: absent, `null`, `false`, `''`, `0` and empty
lists are falsy.

A variable whose path does not exist in the context is **left as-is** in the
document, so template mistakes stay visible instead of silently disappearing.

### Helpers

The engine ships no helper: formatting is the integrator's concern. Register
them at construction; arguments reach the callable in template order, string
literals as written and paths resolved through the context ('' when absent):

```php
$engine = new TemplateEngine(helpers: [
    'date' => fn(string $format, string $value): string => /* ... */,
    'money' => fn(string $amount, string $currency = 'EUR'): string => /* ... */,
]);
// {{date "DD/MM/YYYY" quotation.dueAt}} calls $helpers['date']('DD/MM/YYYY', '2026-07-31T00:00:00+02:00')
```

An unregistered helper raises `UnsupportedFeatureError`; helpers cannot be
applied to loop variables (`item.*`, `@index`) yet.

### Everything else is rejected on purpose

`{{else}}`, partials (`{{> x}}`), subexpressions, `{{#with}}`, block
parameters (`as |x|`), nested blocks, parent references (`../`), `@first` /
`@last`, and triple-stache (`{{{x}}}`) raise an `UnsupportedFeatureError` with
a message suitable for template authors. Malformed constructs (unclosed block,
mismatched closer, invalid path) raise a `TemplateSyntaxError`.

## Placement rules (document structure)

Because rendering mutates a real document, blocks must map onto units the
Docs API can manipulate:

- `{{#each}}`: at most **one per document**, and its opening and closing tags
  must live **inside the same table row**. That row is the prototype: it is
  duplicated once per item (and deleted when the list is empty). `{{item.*}}`
  and `{{@index}}` are only valid inside that row.
- `{{#if}}` / `{{#unless}}`: both tags in the **same table cell**, or both
  entirely **outside any table**. A discarded block outside a table must not
  contain a table (partial table deletions are illegal in the Docs API), and
  conditions cannot live in the loop row.

Violations raise a `TemplateStructureError` explaining what to move.

**Headers, footers and footnotes** are replace-only surfaces: they are parsed
like the body, so `{{variables}}` (whatever their spacing) and comments work
there, but blocks and loop variables are rejected — the Docs API cannot
structurally edit those segments.

## How it renders

`TemplateEngine::render()` issues at most three `batchUpdate` calls:

1. **Structure** — delete discarded condition blocks, insert the empty loop
   rows (bottom-up, so indexes stay valid).
2. **Loop fill** — after re-reading the document (insertions shifted every
   index), write each copy's cell texts, pre-rendered per item, preserving the
   prototype row's text style and alignment.
3. **Replacements** — global `replaceAllText` for variables, comments and loop
   markers; the prototype row becomes item #0.

Nothing is sent when a step has no work to do.

## Errors

Every failure extends `Stann\GoogleDocsTemplate\Exception\TemplateError`:

| Error | Meaning |
|---|---|
| `TemplateSyntaxError` | Malformed construct (unclosed block, invalid path...). Carries the offending `->tag`. |
| `UnsupportedFeatureError` | Valid Handlebars, outside the supported subset. Carries `->tag`. |
| `TemplateStructureError` | Tags valid but placed incompatibly with the document structure. Carries `->tag` when identifiable. |
| `InvalidDocumentError` | The fetched document resource itself is unusable (no body...). |

All messages are written for the person editing the template.

The public API is `TemplateEngine`, the `DocumentClient` interface, the
exceptions and the `Testing\` namespace — everything else is `@internal`.

## Testing

The `Stann\GoogleDocsTemplate\Testing` namespace ships test doubles so
integrators can test without any Google access:

- `Testing\GoogleDocBuilder` builds realistic Docs API document payloads
  (correct index arithmetic for paragraphs, tables, rows, cells, headers,
  footers and footnotes);
- `Testing\FakeDocumentClient` records `batchUpdate` requests and replays
  document snapshots.

The suite itself runs offline:

```bash
composer install
vendor/bin/phpunit
```

## Requirements

PHP ≥ 8.2 with `ext-mbstring`. No other dependency.
