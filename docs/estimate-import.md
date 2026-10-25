# CSV estimate import

Imports existing estimates from a CSV file. The file is data entry only: every imported estimate is a **draft**, and nothing is emailed and no follow-up starts. Sending or activating an estimate is a separate, explicit action.

## Flow

1. **Upload** a `.csv` file (UTF-8, up to 5 MB and 5,000 rows). A sample is available on the page.
2. **Match columns.** Headers are matched by name ("Customer Email" and "email_address" both work). Customer name and amount are required.
3. **Check every row.** Rows with errors are reported, not dropped. Warnings do not block a row.
4. **Confirm and import.** The page states how many estimates will be imported as drafts, and requires a checkbox.
5. **Summary.** Counts for imported, skipped (already imported) and not imported. Problems can be downloaded as a CSV.

## Field rules

| Field | Rule |
|---|---|
| Customer name | Required, up to 200 characters |
| Customer email | Optional. Malformed → row rejected. Missing → customer imported, **not eligible for email follow-ups** (shown as a warning) |
| Amount | Required. 0.01 to 1,000,000.00. Accepts `$` and `,` |
| Estimate date / Expiration date | `2026-10-25` or `10/25/2026`. Expiration before the estimate date → rejected. Expiration already passed → imported, but must be changed before sending |
| Status | One of draft, sent, viewed, accepted, declined, expired, cancelled. Anything else → rejected. The file's status is kept as `imported_status` for reference only; the estimate is always a draft |
| Estimate ID | Optional, used for duplicate detection (below) |
| Description | Optional, becomes the estimate's title and line item |

## Duplicates

- **With an estimate ID:** the ID is stored as `external_reference`, unique per business. A row whose ID was already imported is **skipped** and reported with the existing estimate number. Repeating an import never changes or duplicates anything.
- **Without an estimate ID:** a row is **rejected** as a possible duplicate if an estimate already exists for the same customer (email, or name for customers without email), amount and description. Add an estimate ID to import it anyway.
- **Within one file:** a second row with an ID already used earlier in the file is rejected.

## Customers

Customers are matched by email within the business. Customers without an email are always created as new records (there is nothing to match them on).

## Safety

- Imported rows write estimates and customers **directly**, without dispatching `EstimateCreated` or `CustomerCreated`. Automations react to those events, so dispatching them would let an automation send email.
- No `SendEmailJob` is queued by an import.
- Each row is written in one transaction with its estimate and customer, so a retried job cannot create duplicates.
- The job is unique per import, its timeout is under the queue's retry window, and it can be run again safely.
- Cells that start with `=`, `+`, `-` or `@` are written as text in the problems report, so spreadsheet formulas do not run.
- The stored file is deleted when the import finishes. Rows keep the values as mapped (not the whole file).
- Plan limits apply to estimates and customers. If the plan runs out, the remaining rows are reported with the reason.

## Limits

- Not done yet: activation of imported estimates (the follow-up sequence and review step), importing from other software, editing rows in the browser, and resuming an import after a failure other than starting it again.
- Imported estimates must be sent from the estimate page like any other draft.
- Tests use MySQL. The migrations use only portable column types and composite foreign keys, but they have not been run on PostgreSQL in this environment.
