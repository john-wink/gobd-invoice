# Security Policy

## Supported Versions

Security fixes are applied to the latest minor release line. Until `1.0.0`,
only the most recent release is supported.

| Version | Supported          |
| ------- | ------------------ |
| latest  | :white_check_mark: |
| < latest| :x:                |

## Reporting a Vulnerability

If you discover a security vulnerability within this package, please use the
GitHub **"Report a vulnerability"** feature (Security advisories) on the
repository, or email **john-coding@ys.consulting** directly.

Please **do not** open a public issue for security problems.

All security vulnerabilities will be promptly addressed. You will receive an
acknowledgement within 72 hours.

Because this package handles invoices and tax-relevant data, please also flag
any issue that could compromise the **integrity or immutability** of finalized
documents, the audit trail, or the numbering sequence — even if it is not a
classic memory-safety/injection vulnerability — as these have legal
(GoBD) implications.
