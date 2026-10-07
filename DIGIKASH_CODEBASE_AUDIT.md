# DigiKash 2.0 Codebase Audit

**Audit scope:** repository source and configuration inspection only. No source, configuration, schema, dependencies, or application state was changed. This document is the only intended addition. The repository does not contain a `tests/` directory, Docker/Compose files, or visible CI configuration at its root. Findings describe code present in this checkout; they do not establish production deployment settings, live provider configuration, or runtime behavior against a production database.

## 1. Executive Summary

DigiKash is a feature-rich, conventional Laravel monolith with user/admin/merchant/agent experiences, a server-rendered Blade frontend, many payment adapters, and a newer P2P service that uses database transactions and row locks in its critical lifecycle. It is useful as a product feature inventory and as a source of reusable UI, admin workflows, integrations, and domain knowledge.

It is **not a sound financial core for a production digital-money platform without a substantial core rebuild**. `wallets.balance` is a `FLOAT` and is directly mutated in `App\Services\WalletService`; `transactions` is a mutable, status-oriented record table with no debit/credit ledger accounts or entries. `WalletService::subtractMoney()` checks a previously loaded balance and then decrements it without a transaction/row lock/conditional balance predicate. Transaction handlers apply wallet effects on transaction status completion. This leaves multiple paths exposed to races, repeated side effects, and partial application. Some other flows do explicitly use transactions and locks, so protections are uneven rather than absent everywhere.

**Recommendation: PARTIAL REBUILD.** Preserve stable product surfaces and integrations where code review and provider certification support them. Build a double-entry ledger, immutable journal, idempotent operation boundary, and payment intent/provider event processing before expanding money movement, remittance, crypto, or merchant settlement. Do not describe the existing P2P “escrow” as segregated ledger escrow: it debits a seller wallet and tracks the held amount in an order/transaction record, without a separate escrow ledger account.

Critical additional concern: `.env.example` contains values that look like live credentials or provider identifiers (Twilio and Reverb). They are not repeated here. Rotate any credentials that are real and remove secrets from tracked examples. The sample also sets `APP_DEBUG=true` and development defaults; this must not be used as a production environment file.

## 2. Repository & Technology Stack

| Area | Evidence and finding |
|---|---|
| Framework/runtime | `composer.json`: Laravel Framework `^11.44`, PHP `^8.3`; `composer.lock` pins dependency versions. |
| Frontend | `resources/views/**` is predominantly Blade. `package.json` lists Alpine.js, Axios, Tailwind CSS 3, Vite 5, Laravel Echo 2 and Pusher JS. No Vue or React dependency is declared. |
| Styling/build | Tailwind CSS, PostCSS, Autoprefixer, Vite (`npm run build`). No separate UI component framework is declared. |
| JavaScript/realtime | Alpine, Axios, Echo/Pusher client packages. Laravel Reverb is installed and configured, but `.env.example` defaults broadcasting to `log`; actual realtime deployment is environment-dependent. |
| Database | `.env.example` selects MySQL. `config/database.php` also defines SQLite, PostgreSQL and other Laravel connections. Core wallet migration uses MySQL-compatible floating point/decimal types. |
| Cache/session/queue | `.env.example`: file cache, file sessions, database queue; `config/cache.php`, `session.php`, `queue.php` allow other drivers. Jobs table and failed jobs migrations exist. |
| Authentication | Laravel session/web guards and separate user/admin/merchant/agent authentication controllers. Sanctum is installed; `personal_access_tokens` migration exists. The merchant API uses custom `merchant.auth` middleware, not Sanctum. |
| Authorization | `spatie/laravel-permission`; permission tables migration, role/permission seeders, policies should be confirmed route-by-route. Feature middleware also gates actions. |
| 2FA | `pragmarx/google2fa`, `TwoFactorService`, user/admin fields/controllers and `2fa` middleware. Availability is implemented; enforcement/configuration must be validated per account type and deployment. |
| Storage/mail/notifications | Laravel filesystem abstraction with public image storage; SMTP defaults in `.env.example`; database notifications and template notification channels, plus Twilio notification channel. |
| Jobs/scheduling | `routes/console.php`, `app/Console/Commands/*`, database-backed queue. Commands process subscriptions, wallet earn, P2P expiry/promotions and features. Deployment must actually run scheduler and queue workers. |
| API | `routes/api.php`: custom merchant API v1 and one Sanctum protected Stripe issuing endpoint. `PaymentController` handles payment initiation and verification. |
| Testing | Pest 2 and Laravel Pest plugin are dev requirements, but no `tests/` directory is present. No meaningful repository test suite or coverage evidence was found. |
| Developer tooling | Laravel Pint, Sail, Boost, IDE helper, Laradumps and Laravel Brain are dev dependencies. `artisan`, Vite and Composer scripts are present. |
| Deployment/CI | No Dockerfile, Compose file, workflow, pipeline, or deployment manifest was found at repository root in this inspection. `BuildReleaseCommand`, updater services and project updater configuration exist, but are not a substitute for CI or an operations deployment definition. |

Key dependencies in addition to Laravel include Sanctum, Spatie Permission, Reverb, Stripe PHP, Mollie, Cryptomus, Dompdf, Intervention Image, Google2FA, Twilio notification channel, Bacon QR Code and Purifier. The complete dependency source of truth is `composer.json` / `composer.lock`.

## 3. Actual Architecture

This is a single Laravel application organized around broad HTTP controller families (`Frontend`, `Backend`, `Api`, `Webhook`), Eloquent models, a collection of application services, provider-specific payment adapters, and handler classes selected from transaction type/status. The code is not organized as independent deployable services or strict domain modules.

| Layer | Observed implementation |
|---|---|
| Controllers | Large frontend and backend controller sets. Financial entry points include `Frontend\DepositController`, `WithdrawController`, `SendMoneyController`, `ExchangeMoneyController`, `MerchantPaymentReceiveController`, `PaymentLinkCheckoutController`, `P2P\OrderController`; API `Api\PaymentController`; webhook `Webhook\BitnobWebhookController`. |
| Services | `WalletService`, `TransactionService`, `PaymentService`, `MoneyTransactionService`, `MerchantService`, `CurrencyConversionService`, `P2P\P2POrderService`, `AgentOperationService`, `WalletEarnService`, and provider clients. Business logic is split between controllers, services, transaction handlers, and models/helpers. |
| Repositories | No repository abstraction layer was identified in the inspected application structure. Eloquent queries are issued from controllers and services. |
| Actions/DTOs | `TransactionData` is a DTO; service/handler classes perform actions. There is no consistent command/action boundary across all domains. |
| Requests/resources | Many Laravel Form Requests exist for validation. No broad API Resource layer was observed; API responses are manually composed. |
| Events/listeners/jobs | Laravel event/listener classes include transaction updates, successful login, rank updates and signup bonus. A small job set includes notification/test jobs. Provider webhook events are not uniformly persisted as events/jobs. |
| Policies/middleware | Middleware covers account status, IP blocks, KYC, feature gates, duplicate submissions, 2FA, merchant API authentication and Bitnob signatures. Authorization remains a route/controller-level audit item because routes use varied middleware and policy calls. |
| Providers/helpers/traits | Laravel providers and numerous helpers under `app/helpers.php`; payment services are Composer classmapped from `app/Services/Payment`. This global helper/facade surface increases implicit coupling. |
| Database procedures | No evidence of application-owned triggers or stored procedures found in migrations inspected; money updates are application-side Eloquent/SQL mutations. |

Business logic is not in one place. For example, transfer orchestration is in `SendMoneyController` plus `SendMoneyHandler` and `WalletService`; withdrawal creation/status/refund is in `PaymentService`, `TransactionService` and handlers; exchange orchestration is in `ExchangeMoneyController` and `ExchangeMoneyHandler`; P2P lifecycle is more service-centered in `P2POrderService`.

## 4. Module Inventory

The repository contains user/admin/merchant/agent authentication and management; wallets and currencies; transaction history; deposits and withdrawals; transfers and requests; exchange; gateways; merchant checkout/API/payment links/QR; P2P offers/orders/disputes/promotions; KYC; virtual cards/cardholders; mobile recharge; subscriptions; wallet earn; referrals/rewards; vouchers/gift cards; agents; notifications/support; CMS/blog/pages/themes; updater/license and installer features. Presence of a module does not imply production readiness or regulated operational controls.

Notable files: `app/Services/*`, `app/Services/Handlers/*`, `app/Services/Payment/*`, `app/Services/P2P/*`, `app/Models/*`, `app/Http/Controllers/*`, `app/Http/Middleware/*`, `routes/web.php`, `routes/api.php`, `routes/admin.php`, `routes/auth.php`, `routes/console.php`.

## 5. Database Architecture

Migrations define the following core entities (full table inventory is derivable from `database/migrations/*`):

| Concept | Tables / notable shape |
|---|---|
| Identity and access | `users`, `admins`, `staff`, `personal_access_tokens`, `sessions`, `password_reset_tokens`, Spatie permission/role tables; users have account, verification, role, security and feature fields accumulated through later migrations. |
| Money | `currencies`, `currency_roles`, `wallets`, `transactions`, `deposit_methods`, `payment_gateways`, `withdraw_methods`, `withdraw_accounts`, `withdraw_schedules`, `vouchers`. Wallets link user and currency by foreign keys, have a unique UUID, `balance FLOAT`, boolean-like status, timestamps. Transactions reference user, wallet by string `wallet_reference`, hold type/provider/processing/status, decimal amount/fee/net/payable values and JSON `trx_data`; `trx_id` is nullable and not declared unique in the base migration. |
| Merchants | `merchants`, `merchant_currencies`, `merchant_deposit_methods`, `payment_links`, `transactions` with merchant metadata in JSON. Merchant/webhook/API credentials are fields on merchant records; there is no distinct payment intent or outbox table. |
| P2P | `p2p_settings`, `p2p_payment_methods`, `p2p_offers`, pivot `p2p_offer_payment_method`, `p2p_orders`, `p2p_disputes`, `p2p_offer_feedback`, `p2p_payment_accounts`, promotion packages/purchases. Orders preserve payment-account snapshots, lifecycle state, expiry, amounts/fees and transaction reference. |
| Cards | `virtual_card_providers`, `cardholders`, `businesses`, `virtual_card_requests`, `virtual_cards`, `virtual_card_fee_settings`. Provider IDs and provider data/meta fields support adapter-like integration but do not constitute a normalized provider-event ledger. |
| KYC/security | `kyc_templates`, `kyc_submissions`, `login_activities`, `ip_blocks`, phone verification codes, wallet PIN/user fields. Sensitive KYC documents are represented by submission data and uploaded files; storage access controls require deployment review. |
| Adjacent products | `mobile_recharges`, provider table; subscriptions/plans/features/prices/transactions; wallet earn plans/stakes/rewards; rewards/referrals/ranks; gift cards/templates; agents/operations/commission rules/currencies. |
| Platform/CMS | settings, plugins, features/access rules, notifications/preferences/templates, support tickets/messages/categories, languages, pages/components/content, blogs, SEO, navigation/footer/socials, subscribers, custom landings, updater/license/background-task logs. |

Conceptual ER map:

```text
User ──< Wallet >── Currency ──< CurrencyRole
  ├──< Transaction >── wallet_reference (UUID; not FK)
  ├──< KycSubmission
  ├──< Merchant (merchant user/account) ──< PaymentLink
  ├──< Agent ──< AgentOperation
  ├──< P2POffer ──< P2POrder ──< P2PDispute
  ├──< VirtualCardRequest ── VirtualCard ── Cardholder/Business
  └──< Subscription / WalletEarnStake / MobileRecharge / GiftCard

Merchant ──< MerchantCurrency / MerchantDepositMethod
P2POffer >──< P2PPaymentMethod; P2POrder references payer/receiver accounts
Transaction JSON metadata links merchant checkout, provider references and other workflows
```

Important schema observations: wallet balance is binary floating point; transactions use decimal monetary columns but scale is generally 2 places, while P2P service calculations use 8 decimal rounding. Currency is often stored as a code string in transaction records rather than a currency FK. `wallet_reference`/`trx_reference` are polymorphic string conventions, not referential constraints. The base transaction migration allows nullable, non-unique `trx_id`; indexes/constraints added later must be checked against each deployed schema. The schema does not define ledger accounts, journal entries, account postings, payment intents, provider webhook inbox, or transactional outbox. Many later migrations add fields/indexes, so the current schema is the cumulative migration result, not any single migration.

## 6. Financial Core Analysis

### Deposit

`DepositController::store()` → `PaymentService::depositWithPaymentMethod()` creates a transaction, then the provider adapter (`PaymentGatewayFactory`) initiates payment. Gateway callback/IPN routes reach `Frontend\IPNController`/adapter handling and `TransactionService::completeTransaction()`. The transaction handler is selected and `DepositHandler::handleSuccess()` calls `Wallet::addMoneyByWalletUuid(wallet_reference, net_amount)`, then applies referral rewards and sends notifications. Manual deposit approval uses the same transaction status/handler model.

There is no ledger posting. Credit application is a direct wallet increment; the status transition is protected by a transaction row lock in `TransactionService`, which helps serialize the transaction state but does not itself guarantee the wallet increment and status transition are in the same database transaction across all callers, or prevent all handler replays.

### Withdrawal

`WithdrawController::store()` → `PaymentService::withdrawMoney()` creates pending withdrawal and debits wallet (the service locks the wallet row and executes inside a DB transaction). `processAutomaticWithdrawal()` sends to adapter and records response/status; failed automatic withdrawal refunds via `TransactionService`/handler. Manual admin processing is in backend withdrawal controllers. This is more guarded at creation than generic wallet mutations, but external call, DB status, and refund/retry/idempotency boundaries still need reconciliation and provider-side idempotency.

### User transfer

`SendMoneyController::store()` computes fee/sufficiency and opens a DB transaction; `SendMoneyHandler` handles sender/receiver records by `AmountFlow`, calling wallet subtract/add helpers. The helper-level sufficient balance check can be stale and does not lock; controller pre-check occurs before the transaction. A two-row transfer needs deterministic locking and one atomic posting boundary. Duplicate submission middleware exists, but it is not a durable idempotency key across retries/devices.

### Exchange

`ExchangeMoneyController::store()` calculates conversion/fees through `CurrencyConversionService`, pre-checks source funds, and wraps processing in `DB::transaction`; `ExchangeMoneyHandler` debits source and credits destination by two wallet helper calls. Calculations and transaction fields use floating point in application code; exchange rate/quote fixation and idempotency are not a general financial-core abstraction.

### Merchant payment

Merchant API `PaymentController::initiatePayment()` creates a pending merchant payment transaction and a signed checkout URL. Checkout flows in `PaymentLinkCheckoutController` or `MerchantPaymentReceiveController` collect payment; wallet payments debit customer and credit merchant records through handlers. `TransactionService` emits merchant IPN/webhook after status changes. Provider-based payment method path also exists. There is no distinct payment-intent aggregate, settlement batch, fee/revenue ledger, or outbox-backed webhook delivery. Sandbox transaction logic deliberately skips wallet movements in `PaymentHandler`.

### P2P

`P2POrderService::createFromOffer()` locks offer, method and payment accounts, locks seller wallet, conditionally decrements balance, then creates order and a completed `P2P_ESCROW` transaction within `DB::transaction`. Later release/refund/cancel/dispute-resolution paths in the service re-credit counterparties and update order/transaction state under locks. This is stronger concurrency handling than most generic wallet paths. However, the hold is a debit from the seller’s wallet with amount/status stored on the order/transaction; no separate escrow wallet/account or double-entry postings exist. Thus it is an application-level hold workflow, not ledger-backed segregated escrow.

## 7. Ledger & Accounting Analysis

- Double-entry accounting: **not implemented in schema/code observed**. No chart of accounts, ledger accounts, debit/credit journal entries, balanced posting validation, or immutable ledger transaction model was found.
- `transactions` is a mutable business transaction/status record with `amount_flow`, `amount`, `fee`, `net_amount`, provider and JSON metadata. It is not a double-entry ledger.
- Balance source of truth: **A, mutable wallet balance field**, with transaction history as associated operational records. It is not derived from a ledger. It is also not consistently a safely maintained projection because mutation pathways are heterogeneous.
- Holds/escrow: P2P seller funds are removed from spendable wallet balance and represented in P2P order/status + transaction data. No escrow liability/asset account exists.
- Platform revenue, fee, clearing, settlement accounts: no general ledger accounts found; fees are fields/calculations on transactions and module settings.
- Immutability: financial transaction rows are status/metadata updated by services and webhooks. No append-only journal guarantee exists.
- Monetary precision: wallet migration `2024_11_12_040813_create_wallets_table.php` declares `float('balance')`; `Wallet` casts balance to float. Transaction amount fields are `decimal(15,2)`; P2P uses float values and `round(..., 8)`. This conflicts with exact decimal/integer minor-unit arithmetic.

### Wallet mutation inventory (representative explicit paths)

The shared `WalletService::addMoney()` and `subtractMoney()` use Eloquent `increment`/`decrement`; `subtractMoney()` reads `$wallet->balance` first and then decrements. Those methods themselves do not wrap a database transaction or lock. UUID helpers in the same class delegate to these methods.

| Call site | Operation | Transaction/lock evidence | Duplicate/race assessment |
|---|---|---|---|
| `app/Services/WalletService.php::addMoney/subtractMoney` | Generic credit/debit | None in methods | Calls can race; stale sufficiency check; no generic idempotency. |
| `app/Services/Handlers/DepositHandler.php::handleSuccess` | Deposit credit | Called through status lifecycle; handler itself no lock | Repeated invocation can credit twice unless status/idempotency gating blocks it. |
| `SendMoneyHandler::handleSuccess`, `ExchangeMoneyHandler::handleSuccess`, `PaymentHandler::handleSuccess` | Transfer, FX, merchant payment debit/credit | Call sites use shared mutations; surrounding callers vary | Handler replay/partial pair risk; debit and credit are separate mutations. |
| `PaymentService::withdrawMoney` | Withdrawal debit | DB transaction + wallet `lockForUpdate` in service | Better protected at request creation; external processing/retry still needs idempotency/reconciliation. |
| `SendMoneyController::store`, `ExchangeMoneyController::store` | Transfer/FX orchestration | DB transaction, but balance precheck occurs before transaction; helper does not lock | Competing requests can pass precheck; lack of consistent row locking. |
| `P2POrderService` lifecycle | Escrow hold, release/refund | DB transactions and locks; hold uses conditional `balance >= hold` decrement | Stronger race defense, but no ledger escrow; verify every transition/replay and effects. |
| `AgentOperationService` | Agent cash-in/out and customer/agent transfers | Multiple `DB::transaction` + row locks; helper methods mutate balances | Better serialized core, but float arithmetic and non-ledger records remain. |
| `Backend\UserManageController` | Admin adjustment | Controller-specific adjustment and transaction record | Privileged direct adjustment path; audit trail and row lock must be confirmed in method. |
| `GiftCardController`, `MerchantPaymentReceiveController`, `VirtualCardController`, wallet earn/reward handlers | Product-specific debits/credits | Individual flows use varying transaction blocks and status operations | No single boundary or uniform idempotency guarantee. |

The full direct mutation search should be rerun when the codebase changes; global `Wallet` facade/helper calls obscure mutations beyond direct `balance` assignments.

## 8. Financial Integrity & Concurrency

**High-priority evidence:** `WalletService::subtractMoney()` checks an in-memory balance then decrements; `WalletService::addMoney()` increments directly. Wallet is FLOAT. Transaction completion uses a row lock in `TransactionService`, but wallet mutation is not uniformly locked. Transfers/exchanges do a pre-check before the DB transaction. In contrast, P2P and agent workflows show deliberate transactions and row locks. No universal idempotency key model or unique provider event inbox was identified.

Risks include overdraft/double-spend under concurrent generic transfers, repeated deposit credit if a completion handler is invoked more than once, incomplete two-sided transfer/FX posting if one operation fails outside a shared transaction, and duplicate webhook status application. `PreventDuplicateSubmission` is request/UI middleware, not a durable business idempotency contract. Merchant custom auth has timestamp/signature checks and rate limiting, but payment-initiation idempotency still requires explicit persistence/uniqueness.

`TransactionService::completeTransaction()` and `failTransaction()` lock the transaction row and transition status before dispatching status behavior. This is a useful state-machine guard; verify whether all handler side effects participate in the same DB transaction and whether external notifications/callback delivery are retried safely. A DB transaction cannot atomically coordinate a provider HTTP call, webhook delivery, email, and local database without idempotency/outbox/reconciliation mechanisms.

## 9. Payment Gateway Analysis

There is a common `PaymentGateway` interface with `deposit()` and `handleIPN()`, a `PaymentGatewayFactory` match-based registry, and individual adapter classes under `app/Services/Payment`. This is a real but narrow abstraction. It does not normalize payment intent lifecycle, capture/refund/cancel, settlement, provider events, retry, or idempotency across providers. Payout functionality appears in provider-specific services/adapters and `PaymentService` status logic rather than a universal capability interface.

Providers represented in source/factory: Airtel, Binance Pay, Bitnob, Bitpayserver, Blockchain, Block.io, Cashmaal, Coinbase, CoinGate, CoinPayments, Cryptomus, Flutterwave, Instamojo, Mollie, Moneroo, MTN, NOWPayments, Paymob, PayPal, Paystack, Razorpay, Stripe, StroWallet, 2Checkout, Voguepay, plus manual processing. Presence in code is not evidence of a currently active provider contract or deployed credentials.

Gateway credentials are generally configured in database-backed `payment_gateways` / deposit methods and admin gateway tooling, with some provider-specific configuration. Review `PaymentGatewayController`, provider classes and secrets handling before use. No general encrypted-secret vault integration or environment separation assurance is established by repository files alone.

## 10. Webhook / IPN Analysis

Inbound gateway callbacks are primarily routed through `IPNController` to provider adapter `handleIPN()` and transaction status handling. Bitnob has an explicit `bitnob.signature` middleware and `VerifyBitnobSignature`, which checks raw-body HMAC SHA-256/SHA-512 in hex/base64 using configured gateway secret. **If the secret is blank or a placeholder, the verifier returns `true`**; therefore webhook authenticity is disabled until a real secret is configured. The Bitnob controller catches all handler errors, logs them, then returns HTTP 200; this suppresses provider retries and has no durable inbox/replay mechanism. It has no event-ID uniqueness or raw-payload persistence table. Card event handlers directly change transaction/card status; deposit/payout paths use different services.

Other gateways implement signatures individually (e.g. Binance Pay has signature verification code). The audit did not find a common middleware/event inbox/replay defense for all providers. Provider-specific verification, timestamp tolerance, duplicate behavior, amount/currency/provider-reference validation, retry semantics and terminal transition handling must be reviewed independently.

Outbound merchant IPN is initiated from `TransactionService::sendMerchantPaymentIPN()` after state changes. Merchant fields include webhook URL/secret/environment; delivery tracking, retry scheduling and durable delivery records are not evidenced as a general outbox. No transactional outbox pattern or webhook delivery table was found in migration inventory. Callback payload/secret-signature implementation is provider/application-specific; merchant callback DNS/SSRF controls and secret rotation are deployment/security review items.

## 11. Merchant API Analysis

`routes/api.php` exposes `/api/v1/initiate-payment`, `/verify-payment/{trxId}` and `/site-info` behind `merchant.auth`; a separate Sanctum route serves Stripe issuing ephemeral keys. `MerchantApiAuth` resolves merchant credentials, selects sandbox/live environment, enforces timestamp tolerance (clamped 60–900 seconds), verifies HMAC-SHA256 signature over timestamp, method, request URI and raw body, and rate limits. The code includes merchant identity and environment checks. This is a custom signed API, not Sanctum token/scopes; merchant secrets are the credential model.

Payment request path: Form Request `PaymentInitiateRequest` → `PaymentController::initiatePayment()` validates merchant/currency/method selection and amount details → begins DB transaction → creates pending `Transaction` → commits → encrypts transaction ID → creates expiring Laravel signed checkout URL → returns `payment_url`. Checkout/payment route then processes payer funds and merchant credit through the transaction handlers, with `PaymentHandler` treating customer and merchant `AmountFlow` legs separately. `verifyPayment()` checks transaction type, merchant ID and environment, then returns status.

API version prefix exists (`v1`), but no idempotency-key field/record is visible in this route/auth path. Payment intent abstraction and merchant refunds are not apparent in the three API endpoints. Merchant webhook configuration exists, but robust delivery retry/outbox behavior is not shown. Request validation and timestamped request signing are strengths; no claims of end-to-end security should be inferred without reviewing all checkout authorization, sandbox separation and webhook behavior.

## 12. P2P & Escrow Analysis

P2P has offer/payment method/payment account/order/dispute/feedback/promotion entities, explicit enums, expiry commands, order and dispute controllers, and a centralized `P2POrderService`. It models payment-window expiry, mark-paid, release, cancellation/refund, dispute and admin resolution. Payment account snapshots are persisted on order. Order creation and major transitions use DB transactions and `lockForUpdate`; dispute rows have a uniqueness/performance migration.

Seller wallet funds are conditionally debited at order creation; subsequent release/refund flows credit buyer/seller wallets and update order/transactions. This provides an application-level hold lifecycle and guards against some concurrent order races. It is not a proper accounting escrow: no escrow asset/liability ledger accounts or balanced entries are present, and the wallet balance is the only spendable balance. Auto-expiry relies on scheduler/command execution (`ExpireP2POrders`). Dispute evidence and admin decisions exist at application level; operational evidence retention and adjudication controls need policy review.

## 13. KYC & Security Analysis

Positive controls visible: Laravel password hashing/auth/session patterns, email verification routes, account-status middleware, 2FA services, wallet PIN, KYC gating middleware, IP block/login activity records, secure-header middleware, request validation, CSRF protections through Laravel web stack, Sanctum token support, and a custom timestamped HMAC merchant API.

Risks/gaps from evidence:

1. `.env.example` contains credential-like Twilio/Reverb values, plus debug/development settings. Treat values as exposed if real; rotate them and ensure sanitized examples.
2. `VerifyBitnobSignature` explicitly accepts callbacks when no real webhook secret is configured. This creates an unauthenticated webhook path under misconfiguration.
3. No uniform webhook event persistence, replay/idempotency mechanism or outbox found.
4. Wallet PIN and 2FA security depends on hashing, throttling, recovery, and secret encryption details. Review `WalletPinController`, `TwoFactorService`, user/admin schema and reset flows before production authorization decisions.
5. KYC documents are uploaded/stored; review private-disk policy, signed download authorization, retention, encryption, malware scanning and access logs in deployment.
6. No API version-wide throttling policy is apparent in route snippet; merchant middleware does rate limiting, while web routes apply selected route throttles.
7. Laravel models/JSON metadata, admin extensibility, plugin/update/install features create a large attack surface. Review authorization on every admin action, upload path, updater signature and production installer lock.

RBAC uses Spatie Permission and seeded permission definitions; the presence of role middleware is not proof every sensitive action is policy-authorized. No broad audit-log ledger for privileged financial adjustments was found in table inventory beyond transaction and login records.

## 14. Virtual Card Analysis

Virtual card providers, cardholder/business records, requests, cards and fee settings exist. Provider interface/capabilities are represented via provider classes/config and universal metadata fields; Bitnob is prominent in webhook/card flows and Stripe has an issuing ephemeral-key endpoint. It is partially provider-configurable, but no single provider-neutral lifecycle/event/financial adapter contract was established. Top-up/withdrawal/fees and provider transactions use application transaction records and callbacks. Failure/reversal handling and card-debit events are provider-specific; Bitnob card debit/reversal callbacks only append latest raw event into card `meta` and do not create ledger postings. Card balance reconciliation, duplicate events and wallet/card float accounting need a rebuild-level design.

## 15. Frontend Analysis

The user/admin/merchant/agent surfaces are primarily Blade-rendered templates under `resources/views`, with Alpine/Axios and Vite/Tailwind. There is no declared SPA framework or centralized client-side state manager. API communication is used for AJAX/merchant integrations, but most product UI follows server-rendered Laravel routes and forms. Wallet, transaction, merchant checkout, P2P, admin, virtual cards and other product templates exist. Responsive strategy is CSS/Tailwind/template-based; mobile support includes PWA assets and service worker views.

Reuse potential is good for presentation and CMS/admin CRUD templates after visual/product review. Financial UI should be retained only after API contracts are moved to a safe core; presentation cannot encode authorization or accounting correctness.

## 16. Testing Analysis

Pest dependencies and factories exist, but **no `tests/` directory exists** in this checkout. No unit, feature, API, payment, webhook, P2P, authorization, or concurrency test cases were available to inspect. Meaningful coverage is therefore unsubstantiated; no coverage percentage is reported. Database factories for many newer features are useful beginnings, not evidence of tested behavior. Before financial release, add deterministic tests for ledger invariants, duplicate requests/events, transaction rollback, concurrency/locking, provider signatures, merchant authorization, P2P escrow transitions, reversals and reconciliation.

## 17. Code Quality & Technical Debt

Strengths: clear Laravel conventions in many areas, domain enums/requests/DTOs, service extraction, provider classes, explicit P2P lifecycle service, and increasingly thoughtful locking in newer workflows.

Debt: business rules are spread across oversized controllers, generic helpers/facades, models, handlers and services; no consistent module boundary, repository layer, immutable financial core, or uniform transaction/idempotency pattern. `Wallet` has float casts and convenience methods; `WalletService` is a global mutation path. Transaction rows combine disparate workflow state in JSON. Payment factory is a central match statement and the interface only expresses deposit/IPN. Many integrations expand the operational verification burden. Mixed naming/legacy conventions and long-lived migrations indicate an evolving monolith with accumulated schema changes.

Dangerous coupling: transaction status change invokes handler chosen by transaction type, and handlers directly alter wallets/notify users; merchant callbacks can be coupled to transaction completion. This makes an apparently simple status update potentially a financial side effect. Repeated or out-of-order status handling can therefore affect money unless every call path is guarded and atomic. `app/helpers.php`, global facades, and polymorphic string references further obscure dependencies.

## 18. Target Architecture Comparison

| Target principle | Current state | Assessment |
|---|---|---|
| Double-entry ledger is source of truth | No ledger tables/services observed; wallet balance is mutable | Missing; rebuild before scale. |
| Wallet projection/materialized balance | Wallet balance is primary mutable float | Fails target. |
| All money through core | Shared helpers exist, but modules call wallet mutations/status handlers directly and inconsistently | Partial convention, not enforced boundary. |
| Atomic money operations | Some DB transactions (notably withdrawal, agent, P2P); mixed elsewhere | Inconsistent. |
| Mandatory idempotency | Duplicate-submit middleware and provider-specific references/signatures; no universal idempotency record/event inbox | Missing as a platform invariant. |
| Auditable financial records | Mutable transaction rows and metadata; no append-only journal | Insufficient for accounting source of truth. |
| Provider adapters | Many provider classes plus factory and narrow interface | Partial; lifecycle/capability contract incomplete. |
| Authenticated/persisted/idempotent/retryable webhooks | Signature implementation varies; Bitnob blank-secret bypass; no inbox/outbox | Material gap. |
| Ledger-integrated P2P escrow | Order lifecycle and wallet debit/credit, no escrow accounts | Not ledger escrow. |
| Exact monetary arithmetic | Float wallet, float calculations, mixed 2/8 scale decimals | Fails target. |

## 19. KEEP / REFACTOR / REBUILD / REMOVE / NEW Matrix

| Module | Existing Implementation | Quality | Target Requirement | Decision | Risk | Reason |
|---|---|---|---|---|---|---|
| Authentication | Laravel session guards, verification, role-specific flows | Moderate | Strong auth/session controls | REFACTOR | High | Preserve standard flows; consolidate account lifecycle and test. |
| Users | Eloquent user model with many feature/security fields | Moderate | Stable identity/KYC linkage | KEEP | Medium | Reuse identity and product profile with schema review. |
| RBAC | Spatie permissions, role/permission tables/seeders | Moderate | Tested least privilege | REFACTOR | High | Audit every route/action and privileged financial permission. |
| KYC | Templates/submissions/files + gating | Moderate | Verifiable, private, auditable KYC | REFACTOR | High | Reuse workflow/UI; strengthen storage, review and decision audit. |
| Wallets | FLOAT balance, direct mutation helpers | Poor for fintech core | Projection of ledger | REBUILD | Critical | Replace balance authority with ledger-backed accounts/projections. |
| Currencies | Currency/roles, conversion service | Moderate | Exact currency metadata/scale and FX quote | REFACTOR | High | Preserve catalog; normalize precision and quote rules. |
| Transactions | Mutable status record/JSON metadata | Poor as accounting | Immutable operation + journal references | REBUILD | Critical | Keep as history only after model separation/migration. |
| Ledger | None found | Missing | Balanced double-entry postings | BUILD NEW | Critical | Required source of truth. |
| Deposits | Gateway/manual requests + handlers | Mixed | Payment intent, verified event, idempotent credit | REBUILD | Critical | Rebuild money boundary; retain adapter code selectively. |
| Withdrawals | Service debits and provider/manual status/refund | Mixed | Reserve/settle/release accounting lifecycle | REBUILD | Critical | Needs ledger hold, payout state machine and reconciliation. |
| Transfers | Controller + handlers + wallet helpers | Poor | Atomic double-entry transfer, idempotency | REBUILD | Critical | Concurrent race/partial side effect risk. |
| Exchange | Controller/service and source/destination handlers | Mixed | Quote, exact conversion, fee posting, atomicity | REBUILD | Critical | Reuse UX/FX product rules only. |
| Fees | Settings and per-flow calculations | Mixed | Explicit revenue postings/rounding policy | REFACTOR | High | Centralize policy and ledger account mapping. |
| Payment gateways | Many provider adapters/factory | Moderate | Capability interfaces and provider contracts | REFACTOR | High | Retain verified adapters; normalize lifecycle and secrets. |
| Merchant | Models/controllers, checkout, webhooks | Moderate | Merchant accounts, settlement and audit | REFACTOR | High | Reuse merchant UX; rebase settlement on ledger. |
| Merchant API | Signed v1 custom middleware and payment endpoints | Moderate | Versioned idempotent API and authorization | REFACTOR | High | Good HMAC foundation; add durable idempotency and contracts. |
| Payment intents | None distinct | Missing | Intent/capture/failure/expiry lifecycle | BUILD NEW | High | Necessary decoupling of checkout and money operation. |
| Payment links | Model/service/controllers/Blade checkout | Moderate | Intent-backed, replay-safe links | REFACTOR | High | Reuse UI and nonfinancial link behavior. |
| QR | QR code service/views | Good utility | Signed, scoped payment reference | KEEP | Low | Reusable utility; ensure generated targets are authorized. |
| Webhooks | Provider IPN + merchant callback logic | Poor/inconsistent | Durable inbox/outbox, verified/idempotent/retryable | REBUILD | Critical | No uniform persistence/replay/outbox; config bypass exists. |
| P2P | Rich offer/order/dispute service, locks | Moderate | Explicit lifecycle on ledger | REFACTOR | Critical | Preserve marketplace domain; reimplement money lifecycle. |
| Escrow | Wallet debit plus order/status/transaction | Insufficient | Segregated ledger liability/asset postings | REBUILD | Critical | Current hold is not accounting escrow. |
| Disputes | P2P dispute entities, admin resolutions | Moderate | Evidence, audited decisions, balanced release/refund | REFACTOR | High | Preserve workflow; tie outcomes to ledger posting. |
| Virtual cards | Provider models, requests/cards, Bitnob/Stripe | Moderate | Provider-neutral lifecycle and ledger/reconciliation | REFACTOR | High | Keep provider-specific integrations after event/security audit. |
| Recharge | Provider contract/service and recharge records | Moderate | Idempotent external fulfilment and reversal | REFACTOR | Medium | Product domain can survive; financial debits must use ledger. |
| Subscriptions | Plans/subscriptions/transactions/commands | Moderate | Entitlements and billing state | KEEP | Medium | Separate from money core; move charges to payment intents/ledger. |
| Earn | Plans/stakes/rewards and processing commands | Mixed | Liability accounting, accrual and redemption | REFACTOR | High | Preserve product concept; redesign all balances/accruals. |
| Agents | Agent operations/commission rules, locking | Moderate | Ledger-posted cash-in/out and commissions | REFACTOR | High | Good operation domain; migrate posting logic to core. |
| Notifications | DB notifications/templates/channels | Moderate | Reliable post-commit delivery | KEEP | Medium | Reuse templates/channels; deliver from outbox/jobs. |
| Audit | Login activity and transaction records | Insufficient | Append-only operator and financial audit | BUILD NEW | High | Add immutable event/audit trail around privileged operations. |
| Admin | Broad Blade CRUD/operational UI | Moderate | Least privilege and audited actions | REFACTOR | High | Reuse UX; secure each sensitive action and use new core APIs. |
| Frontend | Blade/Tailwind/Alpine views and PWA | Moderate | Client separated from financial authority | KEEP | Medium | Reuse presentation after API/service boundary changes. |
| Cross-border remittance | No distinct domain found | Missing | Corridor/compliance/payout/FX workflows | BUILD NEW | High | Do not infer from gateways. |
| Crypto wallets | No self-custody wallet/accounting domain found | Missing | Custody/key management and chain accounting | BUILD NEW | Critical | Crypto checkout providers do not equal crypto wallets. |
| Crypto deposits/withdrawals | Bitnob stablecoin provider flow exists | Partial provider flow only | Chain-specific monitoring, custody/ledger/reconciliation | BUILD NEW | Critical | Do not equate provider webhook support with platform crypto accounts. |
| Crypto swaps | No distinct swap domain found | Missing | Quote, execution, custody and compliance | BUILD NEW | High | Not evidenced in source. |

## 20. Architectural Risk Register

| Severity | Area | Finding / evidence | Impact | Recommended remediation |
|---|---|---|---|---|
| CRITICAL | Accounting | `wallets.balance` is FLOAT (`2024_11_12_040813_create_wallets_table.php`); no ledger tables; `WalletService` mutates balance directly. | Rounding drift, unreconciled funds, inability to prove balanced books. | Build double-entry ledger with integer minor units/decimal arithmetic, immutable postings, reconciliation and projections. |
| CRITICAL | Concurrency | `WalletService::subtractMoney()` uses stale balance check then decrement without own lock/transaction; generic transfer/exchange prechecks precede DB transaction. | Concurrent requests can overspend or produce inconsistent paired entries. | Route all money through locked, atomic, idempotent ledger posting boundary. |
| CRITICAL | Webhook security | `VerifyBitnobSignature` returns true for blank/placeholder secret. | Forged events can alter card/payout/deposit transaction statuses when misconfigured. | Fail closed; require secret during provider activation; test signatures and key rotation. |
| CRITICAL | Webhook replay/duplication | No common webhook inbox/event uniqueness; Bitnob controller catches errors and always acknowledges 200; transaction/card handlers may update directly. | Duplicate credit/status changes or lost provider retry with no durable replay. | Persist raw events, enforce provider event uniqueness, enqueue idempotent processors, return retry-appropriate responses and reconcile. |
| CRITICAL | P2P escrow | P2P hold is wallet decrement + order/status, without escrow ledger accounts. | Accounting liability/ownership ambiguity; dispute/refund defects can create or lose value. | Rebuild escrow transitions as balanced ledger postings and hold accounts. |
| HIGH | External retries | No universal idempotency key/operation record evident in merchant or internal money entry points. | Client/provider retries can create duplicate operations. | Add durable scoped idempotency records and unique external references. |
| HIGH | Transaction side effects | Status handlers mutate wallet and notify/callback; status and effects are coupled across `TransactionService`/handlers. | Replay, partial commit, or callback failure can desynchronize money and statuses. | Separate immutable command/posting from state transitions; transactional outbox for downstream effects. |
| HIGH | Secrets | `.env.example` contains credential-like Twilio/Reverb values and dev debug defaults. | Credential compromise if values are real or copied to production. | Rotate real values, sanitize tracked example, enforce production config checks. |
| HIGH | Merchant delivery | Outbound IPN is called from transaction status path; no outbox/delivery record table found. | Lost or duplicated merchant notifications, untracked retries. | Outbox + signed delivery attempts, retries and per-event idempotency. |
| HIGH | Privilege/audit | Admin financial adjustment paths coexist with generic transaction/wallet operations; no comprehensive immutable audit log found. | Insider misuse or disputed manual balance changes. | Require dual control/permissions, reason codes, immutable operator audit and ledger adjustment entries. |
| HIGH | Provider payout | Provider calls and local statuses/refunds span external boundaries. | Ambiguous timeout can trigger duplicate payout or inconsistent refund. | Provider idempotency keys, state reconciliation, payout suspense/hold accounts. |
| MEDIUM | Testing | No tests directory despite Pest dependencies. | Regressions and concurrency/security gaps cannot be demonstrated as controlled. | Establish invariant-focused suite before financial core rollout. |
| MEDIUM | Operations | No deployment/CI/container definitions observed; scheduler/queue correctness depends on ops. | Jobs, expiry, notifications and retries may silently not run. | Add documented, monitored deployment topology and queue/scheduler health checks. |
| MEDIUM | KYC/private data | KYC uploads and personal data are present; storage access/retention are deployment-specific. | Privacy exposure and compliance failures. | Private storage, signed access, encryption, retention and access audit. |

## 21. Recommended Migration Strategy

Use a strangler migration rather than replacing every product surface at once:

1. Freeze expansion of money-moving features until core boundaries and reconciliation requirements are agreed.
2. Map deployed data and provider references; define currency scales, rounding rules, opening balances, liabilities, fees and treatment of pending operations.
3. Build a new ledger service/schema with balanced immutable entries, account ownership, holds, idempotency and audit metadata. Provide balance projections and reconciliation against existing wallets.
4. Run shadow postings/read-only reconciliation on representative flows. Establish variance handling and signed-off migration balances before cutover.
5. Route one low-complexity internal transfer through the new core; then deposits/withdrawals with inbox/outbox and provider idempotency; then merchant settlement, FX, P2P escrow, cards and agent operations.
6. Keep current UI/routes where possible, replacing their mutation path behind application services. Maintain a controlled rollback path that does not allow two systems to authoritatively mutate the same balance.
7. Reconcile provider settlements and outstanding transactions before retiring legacy balance logic. Do not dual-write money without deterministic ledger idempotency and reconciliation.

## 22. Recommended Development Roadmap

**Phase 0 — safety and discovery:** rotate any real credentials present in examples; document production config; enumerate deployed provider integrations and schema state; classify outstanding pending transactions; define accounting and operational requirements.

**Phase 1 — financial foundation:** exact monetary types; chart of accounts; append-only double-entry journal; posting API; holds/reservations; idempotency; audit trail; reconciliation reports; locking/atomicity; test harness for concurrency and invariants.

**Phase 2 — core money flows:** internal transfers, deposit credit, withdrawal reserve/settlement/refund, fee postings and FX quotes. Implement provider event inbox and outbound outbox before enabling callbacks.

**Phase 3 — merchant:** payment intents, merchant authentication/authorization, idempotency, checkout, refunds, settlement, signed webhook delivery with durable retry, reconciliation dashboard.

**Phase 4 — P2P:** ledger-backed escrow hold/release/refund, dispute outcomes, expiry jobs, evidence retention, race and retry tests.

**Phase 5 — adjacent products:** agents, subscriptions, earn, recharge, gift cards and virtual cards migrated one at a time onto the core posting API; prove reversal handling for each provider.

**Phase 6 — future regulated domains:** treat cross-border remittance and crypto custody/deposits/withdrawals/swaps as new domains requiring separate legal/compliance, custody, sanctions, reconciliation and ledger design. Existing crypto payment gateways alone do not implement these domains.

## 23. Final Go/No-Go Recommendation

### A. Should we continue with DigiKash 2.0?

**PARTIAL REBUILD.** Continue using the repository as a product shell and domain reference, but do not extend the present wallet/transaction design as the authoritative financial platform.

### B. What percentage is realistically reusable?

Qualitatively, **a substantial portion of nonfinancial product surface is reusable**, while **little of the financial authority should be reused unchanged**. Blade/admin/CMS views, identity flows, notification templates, provider adapter starting points, P2P marketplace UX, and selected KYC/support/product management code can be retained after security and quality review. Wallet balance mutation, transaction-to-money side effects, escrow accounting, webhook processing, settlement and payout idempotency need redesign. A single platform-wide percentage would imply false precision; assess by module and operation.

### C. What should NOT be rewritten?

Do not rewrite stable presentation/CMS and basic Laravel identity primitives merely for architectural fashion. Preserve reviewed gateway-specific protocol knowledge, QR/PWA assets, notification templates, and domain workflows where they can be placed behind new interfaces. Reuse remains conditional on security, licensing, provider certification and tests.

### D. What MUST be rebuilt before adding new fintech functionality?

The financial core: double-entry ledger, exact arithmetic, wallet projections, atomic/idempotent posting API, pending/hold/settlement/reversal states, provider webhook inbox, merchant outbox, audit/reconciliation, and ledger-backed P2P escrow. Replace all direct wallet balance mutation paths before expanding transfers, payout, cross-border, crypto, or merchant settlement.

### E. What should we build first?

Start with the ledger and reconciliation model, including migration of opening balances and pending liabilities. In parallel establish financial invariant tests and a provider webhook inbox/outbox. Then migrate one internal transfer end-to-end, followed by deposits/withdrawals, merchant payments, and P2P escrow. Only then add new remittance or crypto domains.

---

## Evidence Index (primary files)

- Stack/config: `composer.json`, `composer.lock`, `package.json`, `.env.example`, `config/database.php`, `config/cache.php`, `config/queue.php`, `config/session.php`, `config/sanctum.php`, `config/reverb.php`.
- Routes: `routes/web.php`, `routes/api.php`, `routes/admin.php`, `routes/auth.php`, `routes/console.php`.
- Schema: `database/migrations/2024_11_12_040813_create_wallets_table.php`, `database/migrations/2024_11_16_150322_create_transactions_table.php`, all remaining migrations under `database/migrations/`.
- Financial core: `app/Models/Wallet.php`, `app/Models/Transaction.php`, `app/Services/WalletService.php`, `app/Services/TransactionService.php`, `app/Services/PaymentService.php`, `app/Services/MoneyTransactionService.php`, `app/Services/Handlers/*`.
- Entry flows: `app/Http/Controllers/Frontend/DepositController.php`, `WithdrawController.php`, `SendMoneyController.php`, `ExchangeMoneyController.php`, `MerchantPaymentReceiveController.php`, `PaymentLinkCheckoutController.php`.
- Providers/API: `app/Services/Payment/PaymentGateway.php`, `PaymentGatewayFactory.php`, provider directories; `app/Http/Controllers/Api/PaymentController.php`, `app/Http/Middleware/MerchantApiAuth.php`, `app/Http/Controllers/Frontend/IPNController.php`.
- Webhooks/P2P: `app/Http/Middleware/VerifyBitnobSignature.php`, `app/Http/Controllers/Webhook/BitnobWebhookController.php`, `app/Services/Bitnob/BitnobDepositService.php`, `app/Services/P2P/P2POrderService.php`, `app/Console/Commands/ExpireP2POrders.php`, backend P2P dispute controllers.
- Security/frontend/tests/deployment: `app/Services/TwoFactorService.php`, `app/Http/Middleware/*`, `app/Http/Controllers/Frontend/KycSubmissionController.php`, `resources/views/**`, `database/factories/**`; no `tests/` directory or root Docker/CI deployment files were present.
