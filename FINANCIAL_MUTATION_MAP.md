# DigiKash 2.0 — Financial Mutation Surface Map

**Scope:** analysis of the existing checkout only. No application code, migrations, dependencies, configuration, or prior audit report was changed. This is a source-code map, not a statement about live provider settings or production behavior. Paths below are relative to repository root; method names are provided for stable navigation because exact line numbers may move.

## 1. Executive Summary

`wallets.balance` is changed through multiple paths. The shared mutation point is `App\Services\WalletService::addMoney()` / `subtractMoney()`, but it is bypassed by direct `increment()` / `decrement()` in `WalletEarnService`, `AgentOperationService`, and `P2POrderService`. Most product flows call the shared helper directly from handlers or services; others mutate immediately and separately write a transaction row.

The central transaction transition path (`TransactionService::completeTransaction()` / `failTransaction()`) locks the transaction row and executes state update plus success/fail handler inside a DB transaction. It only dispatches a handler for deposit, receive-payment, request-money and withdrawal types. Send/exchange and several newer types execute their own wallet and transaction updates in their flow. `cancelTransaction(..., refund: true)` does not take a transaction row lock or require the source transaction to remain pending before crediting; repeated cancellation/refund calls can therefore duplicate the refund.

Important inconsistency: `TrxStatus` defines only `pending`, `completed`, `canceled`, `failed`; there is no generic `processing`, `success`, `refunded`, or `reversed` transaction status. Some provider paths directly update status, and recharge has a separate `MobileRechargeStatus` enum. Successful transaction state plus wallet effect is therefore not governed by one universal state machine.

The most important identified mutation surfaces are itemized in §2. Certain feature flows have atomic database transactions and entity/wallet locks (P2P order state, agent operations, wallet earn, subscription); generic `WalletService` itself has no transaction, lock, idempotency or conditional balance predicate. A transaction wrapper at a caller is not proof of safety if the wallet row is not locked or the retry path is not idempotent.

## 2. Complete Wallet Mutation Inventory

“Duplicate” means whether the code establishes a durable guard against applying the same logical financial operation twice. Middleware such as `prevent.duplicate` is identified separately; it is not an operation ledger. No implementation below uses a universal idempotency-key table.

| File / class / method | Operation and affected wallet | Trigger / record | DB transaction, row lock, predicate | Idempotency / duplicate risk |
|---|---|---|---|---|
| `app/Services/WalletService.php` — `subtractMoneyByWalletUuid()`, `subtractMoney()` (around lines 61–106) | Debit given wallet; helper reads balance and calls `$wallet->decrement('balance', $amount)` | Any caller below; no transaction record created here | **None inside helper.** Reads possibly stale model balance; no lock; no SQL `balance >= amount` predicate | **No.** Caller must provide all protection. Concurrent debits may pass stale check and overdraw. |
| `app/Services/WalletService.php` — `addMoneyByWalletUuid()`, `addMoney()` (around lines 112–136) | Credit given wallet; `$wallet->increment('balance', $amount)` | Any caller below; no record created here | **None inside helper** | **No.** Repeat invocation credits again. |
| `app/Services/Handlers/DepositHandler.php` — `handleSuccess()` (~25) | Credit transaction wallet by `net_amount` | `TransactionService::completeTransaction()` for deposit; manual admin approval or gateway callback | Invoked inside `completeTransaction()` database transaction; transaction row locked. No separate wallet lock in helper | Central status guard skips non-pending transactions on this path; direct helper replay remains unsafe. Referral reward can be triggered too. |
| `app/Services/Handlers/SendMoneyHandler.php` — `handleSuccess()` (~25, 36) | Sender debit by `payable_amount`, recipient credit by `net_amount` | `SendMoneyController::store()` invokes handler for paired transaction rows | Caller uses DB transaction; helper takes no wallet locks; see §3. Sender/receiver transaction rows are separately created | No persistent operation key; pair-level transaction boundary reduces partial commits but stale balance/races remain. |
| `app/Services/Handlers/RequestMoneyHandler.php` — `handleSuccess()` (~22–34) | Receiver/acceptor debit for `MINUS`; requester/recipient credit for `PLUS` | `RequestMoneyController::store()` approval path; `TransactionService` can also invoke on individual transaction completion | Request controller uses DB transaction; generic complete path locks transaction, not wallet | Paired record flow, but no durable pair idempotency. If handlers are invoked outside guarded lifecycle, duplicate effects possible. |
| `app/Services/Handlers/ExchangeMoneyHandler.php` — `handleSuccess()` (~20–27) | Source debit / destination credit, depending on `AmountFlow` | `ExchangeMoneyController::store()` creates and processes two records | Controller DB transaction; no wallet row locks through helper | No durable exchange idempotency; transaction legs may be inconsistently invoked elsewhere. |
| `app/Services/Handlers/PaymentHandler.php` — `handleSuccess()` (~21–60) | Customer debit (`MINUS`) or merchant wallet credit (`PLUS`); skipped for sandbox | `TransactionService::completeTransaction()` on `RECEIVE_PAYMENT`; checkout has direct processing paths too | Central completion transaction / locked transaction row. Handler wallet helper has no lock. Direct checkout processing uses its own transactions and code path | Central call guarded by pending status. No common two-leg key/ledger posting. Sandbox inferred from remarks/JSON fields. |
| `app/Services/Handlers/GiftCardHandler.php` — `handleSuccess()` (~16–35) | Credit gift-card recipient wallet only for `PLUS` leg | Gift-card redemption calls handler; transaction/user notifications | `GiftCardController` redemption transaction wrapper; no wallet lock via helper | Gift-card status/recipient handling offers some guards; no general financial idempotency. |
| `app/Services/Handlers/VoucherHandler.php` — `handleSuccess()` (~method in file) | Credit redeemer wallet on `PLUS` flow | `VoucherController` redeem flow | Voucher flow wraps relevant work in DB transaction; helper no wallet lock | Voucher code/status guard is operation-specific; no generic idempotency key. |
| `app/Services/Handlers/WithdrawHandler.php` — `handleSuccess/handleFail/handleSubmitted()` | **No wallet mutation** in handler; notifications only | `TransactionService` withdrawal lifecycle | Handler is invoked inside central state transaction for success/fail; notification may be synchronous DB notification or channel-dependent | Does not refund; refund is handled by `TransactionService::cancelTransaction()` or provider paths. |
| `app/Services/TransactionService.php` — `cancelTransaction()` (~185–225) | Credit original wallet by source `payable_amount` when `refund=true`, then create completed `REFUND` row | Provider failure/cancel, admin/manual cancellation | Refund credit + refund row in DB transaction, but **source transaction is fetched before it; no `lockForUpdate` and no pending/terminal guard**. Original cancel status update occurs after refund block | **High duplicate risk:** repeated call with refund can add repeated credits/REFUND rows. Transaction lock used in complete/fail does not protect this method. |
| `app/Services/PaymentService.php` — `withdrawMoney()` (~113–185) | Debit user wallet by withdrawal payable amount | User withdrawal HTTP request | DB transaction and wallet `lockForUpdate`; sufficient-balance check while locked; then `Wallet::subtractMoney()` | Stronger atomic reserve/debit; helper still general. Provider request status and retry idempotency remain provider-dependent. |
| `app/Services/MobileRechargeService.php` — `recharge()` (~79–121) | Debit user wallet by quote total | User mobile recharge request | DB transaction around debit/recharge/transaction creation; no wallet lock here; sufficiency precheck occurs before transaction | Recharge record and transaction IDs identify operation locally but no unique client idempotency key observed. Provider executes after DB commit. |
| `app/Services/MobileRechargeService.php` — `failAndRefund()` (~229–278) | Refund credit by original `payable_amount` | Provider failure/exception | DB transaction; no row lock shown on recharge/transaction; conditional only `transaction->status !== FAILED` on caller-loaded model | Concurrent failure invocations can both observe not-failed and credit. Creates refund record, updates recharge/transaction to failed. |
| `app/Services/SubscriptionService.php` — `subscribe()`, `switchPlan()`, renewal/upgrade paths (e.g. `subtractMoney()` calls around ~94, ~183, ~322, ~707) | Debit default wallet for plan charge; in prorated switch there may be credit as well (inspect separate logic) | Subscription routes, renewal command | Most key flows wrap DB transaction and obtain a locked wallet via `lockedDefaultWallet()` (~633); helper itself no lock, but passed locked model | Subscription/user locks and active-state checks reduce duplicate subscriptions; no generic payment operation key. Renewal command must be scheduled and each path guarded. |
| `app/Services/WalletEarnService.php` — stake creation (~38–60) | Direct conditional decrement of wallet by principal (`where balance >= amount`) | Wallet earn stake HTTP action | DB transaction; plan + wallet rows `lockForUpdate`; conditional balance predicate with direct decrement | Stake is lifecycle record and unique payout rows checked; creation request has no universal idempotency key. Better protected than helper calls. |
| `app/Services/WalletEarnService.php` — `processLockedStakeDuePayouts()` (~384–490) | Direct `wallet->increment('balance', amount)` for each reward | Scheduler/command `ProcessWalletEarnRewards` | DB transaction and stake/wallet locks; checks status/due time and payout-number reward existence | Payout number record prevents many repeats, locks serialize same stake; check+insert is not database uniqueness unless migration enforces it. Notification inside transaction. |
| `app/Services/WalletEarnService.php` — `refundPrincipal()` (~548–590) | Direct wallet increment of principal | Stake completion/cancel/refund lifecycle | Caller typically holds DB transaction; wallet is selected `lockForUpdate` | Stake status transition guards typical path; no ledger posting. |
| `app/Services/AgentOperationService.php` — `debitWallet()/creditWallet()` (~559–574) | Direct decrement/increment | Agent cash-in/out operations; commissions/paired customer-agent movement | Callers perform DB transactions and lock involved customer/agent wallets in operation methods (~194, ~298, ~343); helper itself no lock | Agent operation row/status adds operation identity. More guarded than shared WalletService; inspect unique external/admin retries. |
| `app/Services/P2P/P2POrderService.php` — `createFromOffer()` (~129–207) | Seller wallet conditional debit by `amount + sellerFee` | P2P order create from offer | DB transaction; offer/method/accounts/wallet `lockForUpdate`; SQL `balance >= hold` predicate and decrement | Order lock and lifecycle state prevent offer races. No provider event idempotency relevant to fiat buyer payment, which is user-confirmed. |
| `app/Services/P2P/P2POrderService.php` — release/refund/cancel/dispute methods (~235–677) | Direct helper credits buyer/seller for release, refund, expiry, dispute outcomes | User order action, command expiry, admin dispute action | Each public lifecycle action uses DB transaction; several lock order + wallet (`~293`, `372`, `467`, `554`, `633`); verify exact transition-specific wallet lock in each helper | State checks/row locks prevent many duplicates. Refund/release postings are transaction rows but no escrow ledger. |
| `app/Services/P2P/P2POfferPromotionService.php` — purchase (~131–180) | Debit advertiser wallet for promotion purchase | Promotion purchase endpoint | DB transaction; offer/wallet locks | Promotion purchase entity/limits and locks; still wallet helper, no general idempotency token. |
| `app/Http/Controllers/Frontend/VoucherController.php` — `store()` (~69–105) | Debit wallet for voucher purchase cost | User voucher purchase | DB transaction, but no explicit wallet lock in shown controller; helper no lock | Voucher row and transaction creation; request duplicate middleware may apply by route. |
| `app/Http/Controllers/Frontend/GiftCardController.php` — `store()` (~161–220) | Debit sender wallet by payable amount | Gift-card creation | DB transaction, but no explicit wallet lock in shown code | Gift card record and transaction are atomic; repeated client request may create another gift card unless duplicate middleware catches same request window. |
| `app/Http/Controllers/Frontend/GiftCardController.php` — `redeem()` / cancel/refund path (~264+) | Credits redeeming recipient through `GiftCardHandler`; cancelled gift card may refund sender via transaction handler/explicit code | Recipient redeem or sender cancellation | DB transaction in redeem/cancel flows; lock and status checks are flow-specific | Gift-card state is operation guard; no generic idempotency. |
| `app/Http/Controllers/Frontend/SendMoneyController.php` — `store()` (~96–150) | Creates paired sender/receiver transactions; handler debits/credits | User transfer HTTP request | DB transaction, pre-check balance before transaction; no wallet lock in controller/helper | `prevent.duplicate` route middleware is short-window/request-level only. Concurrent transfers remain unsafe. |
| `app/Http/Controllers/Frontend/RequestMoneyController.php` — `store()` (~78–120) | Paired records and handler debit/credit based on approval | Request/approve money route | DB transaction; no evidence all wallet rows lock | Request-linked transaction references and status; no durable idempotency contract. |
| `app/Http/Controllers/Frontend/ExchangeMoneyController.php` — `store()` (~58–110) | Paired source/destination mutations by handler | User exchange HTTP request | DB transaction; balance precheck before transaction; helper has no lock | Duplicate middleware may cover request route only; operation itself has no key. |
| `app/Http/Controllers/Frontend/MerchantPaymentReceiveController.php` — `processMerchantTransaction()` and direct wallet checkout paths (~746–840) | Customer debit and merchant credit for wallet checkout; voucher branch locks voucher separately | Customer checkout (direct wallet, voucher, gateway path) | Some wallet payment critical section wraps DB transaction (~833); method branches vary; no generalized customer/merchant wallet locking | Transaction status checks + voucher lock on voucher branch; direct state/transaction behavior is separate from API completion handler. |
| `app/Services/PaymentLinkService.php` — payment creation/processing (~376, 506) | Payment-link checkout paired wallet operations or transaction status mutation; exact subflow based on method | Payment-link pay endpoint / successful gateway completion | DB transactions around payment start and state transition; wallet locking not uniformly established by service-level evidence | Usage cap/limits and transaction references exist; no universal idempotency key. |
| `app/Http/Controllers/Backend/UserManageController.php` — `updateBalance()` (~155–247) | Admin credit/debit via Wallet add/subtract helpers | Admin user balance form | Source shows branch calling generic helper; do not infer a DB transaction/row lock absent explicit evidence | Creates an adjustment transaction record according to method; unique idempotency/dual approval not evident. Permission mapping includes `user-balance-manage`. |
| `app/Http/Controllers/Backend/VirtualCardController.php` — card issuance fee path (~480–505) | Debits the selected/default user wallet by card issue fee through `Wallet::subtractMoney()` | Admin/provider card issuance request | No row lock or transaction is visible around this helper call in the inspected method excerpt; card/request/transaction state handling is surrounding code | No idempotency at helper; failed issuance refund path is separate and must be reconciled against card request status. |
| `app/Services/SignupBonusService.php` — award path (~65) | Credit default wallet via helper; creates bonus transaction | Signup verification/listener path (`AwardSignupBonusOnVerified`) and acknowledge flow | Inspect service for surrounding transaction; helper itself none | Signup bonus tracking migration and user flag reduce repeat; code-level idempotency is feature-specific. |
| `app/Services/ReferralService.php` — `rewardReferral()` (~62) | Credit referral wallet via helper; transaction created in service | Successful qualifying deposit or referral event | No wallet lock inherent in helper; caller context depends on deposit completion | Referral reward state/record is feature-specific; check reward uniqueness and potential transaction retry. |
| `app/Listeners/UpdateUserRanking.php` — rank reward path (~79–100) | On rank upgrade, adds configured reward to a wallet via helper | User ranking update/event processing | DB transaction visible around listener method; no lock within shared wallet helper unless wallet explicitly locked in method | Rank transition may guard repeated award; wallet credit should be treated as money creation. |
| `app/Services/Bitnob/BitnobDepositService.php` — `applyDepositSuccess()` | Credits matching stablecoin deposit via wallet/transaction service (invoked by Bitnob webhook) | Verified Bitnob stablecoin deposit webhook | Review service method: event controller verifies signature middleware, but no webhook inbox; transaction-specific status path | Duplicate and out-of-order event handling depends on transaction state; absent event-ID persistence. |
| `app/Services/VirtualCard/VirtualCardManager.php` — card meta balance adjustments (~128–190) | Updates **virtual card `meta.balance`**, not `wallets.balance` | Bitnob card top-up/withdrawal/debit/reversal event handling | Card/provider state updates; not a wallet ledger mutation | Counted separately: this may be a displayed/provider balance projection, not user wallet mutation. Card provider webhook can repeat. |

### Direct mutation completeness notes

Repository-wide source search for `addMoney`, `subtractMoney`, `increment('balance')`, `decrement('balance')`, `balance` assignments/updates, and raw balance updates found the direct wallet writes listed above. The current code has no broad raw SQL `UPDATE wallets SET balance = ...` path in application source. `Wallet::create()` initializes balance to zero (not a balance change to existing funds). `PaymentLink::increment('payments_count')` and gift-card `used_count` are counters, not wallet mutations. `VirtualCardManager` metadata balance is not the `wallets.balance` column.

## 3. Financial Entry Points

| Flow | Entry → orchestration → effect → transaction record |
|---|---|
| Deposit, automatic | `routes/web.php` user deposit POST → `Frontend\DepositController::store()` → `PaymentService::depositWithPaymentMethod()` → provider factory/adapter (`deposit`) → IPN/callback → `TransactionService::completeTransaction()` → `DepositHandler::handleSuccess()` → wallet credit → original deposit `Transaction`; referral reward may follow. |
| Deposit, manual/admin | User deposit store creates pending manual transaction → admin `Backend\DepositController` approve/reject → `Transaction::completeTransaction()` / `cancelTransaction()` → `DepositHandler` credit on success or notification on failure. |
| Withdrawal | User withdrawal POST → `Frontend\WithdrawController::store()` → `PaymentService::withdrawMoney()` (debit under wallet lock/transaction; creates pending `WITHDRAW`) → automatic provider call or manual admin review → adapter/callback or admin decision → `TransactionService` completion/failure/cancel → `WithdrawHandler` notifications; cancellation with refund uses generic refund branch. |
| Transfer | `SendMoneyController::store()` → recipient/fees/limits → creates sender and recipient `Transaction` rows → `SendMoneyHandler` debit/credit inside DB transaction. |
| Request money | `RequestMoneyController::store()` creates request pair/refs → recipient approval/rejection through transaction controller actions → `RequestMoneyHandler` debit/credit and notifications. |
| Exchange | `ExchangeMoneyController::store()` → conversion/fee calculations → creates paired transaction rows and `ExchangeMoneyHandler` debits/credits within DB transaction. |
| Merchant API | `routes/api.php` `/v1/initiate-payment` → `MerchantApiAuth` + `PaymentInitiateRequest` → `Api\PaymentController::initiatePayment()` creates pending receiver-side transaction and signed checkout URL → `MerchantPaymentReceiveController` checkout → customer debit/merchant credit handlers; API verify endpoint reads transaction status. |
| Merchant wallet checkout | Merchant checkout/payment route → `MerchantPaymentReceiveController` → verifies pending transaction and payer → wallet sufficiency → DB transaction and wallet transaction legs → `PaymentHandler` or local completion code. |
| Payment link | Authenticated link creation in `PaymentLinkController` → `PaymentLinkService`; payer enters `PaymentLinkCheckoutController` → service creates linked transaction/checkout or wallet payment → status completion records link success count via `TransactionService::recordPaymentLinkSuccessfulPayment()`. QR accessor in `Transaction`/payment link views generates URL to same checkout; QR itself does not move funds. |
| P2P | `P2P\OrderController` create/release/cancel/paid routes → `P2POrderService` → seller wallet debit for hold then later wallet release/refund → `p2p_orders`, `p2p_disputes`, `transactions`. Admin resolution through backend P2P dispute controller invokes order service. |
| Card | `VirtualCardController` request/topup/withdraw routes, admin card issuance and provider callbacks → virtual-card service/provider manager → wallet fee/debit/credit and `CARD_TOPUP`/`CARD_WITHDRAW` transaction records; provider events can update `VirtualCard` metadata/status. |
| Recharge | User mobile recharge POST → `MobileRechargeController` → `MobileRechargeService::recharge()` debits, creates `MobileRecharge` and `MOBILE_RECHARGE` transaction, then provider manager; provider result/failure updates statuses/refunds. |
| Subscription | Subscribe/switch/cancel UI → `SubscriptionController` → `SubscriptionService` charges wallet, creates `SUBSCRIPTION`/renewal transaction, changes subscription plan/status. `ProcessSubscriptions` command calls renewal processing. |
| Wallet earn | Wallet earn controller → `WalletEarnService` locks/debits stake principal; command `ProcessWalletEarnRewards` credits periodic reward and principal at maturity; transaction rows `WALLET_EARN_STAKE/REWARD/PRINCIPAL`. |
| Rewards/referral/bonus | Signup verification listener → `SignupBonusService`; deposit completion → `DepositHandler` → `ReferralService`; ranking event/listener → `UpdateUserRanking`; reward controllers/seeded rewards may invoke direct wallet credit. They create system transaction records in feature service/listener. |
| Voucher/gift card | User routes → `VoucherController` / `GiftCardController` plus corresponding handler; wallet debit on purchase and credit on redeem/refund; records in voucher/gift-card tables and transaction history. |
| Agent | Agent operation HTTP routes → `AgentOperationController` → `AgentOperationService`; locks paired wallets, performs debit/credit, creates `AgentOperation` and transaction rows; commissions through service/rules. |
| Admin balance adjustment | Backend user management route → `UserManageController::updateBalance()` → helper add/subtract → adjustment transaction + notifications; authorization via `user-balance-manage` permission. |
| Provider callback/webhook | `routes/web.php` IPN route → `Frontend\IPNController` → provider adapter signature/status checks → `Transaction::completeTransaction/failTransaction/cancelTransaction`; Bitnob route → `VerifyBitnobSignature` → `BitnobWebhookController` → deposit/card/payout handlers. |

## 4. Transaction State Machines

### Defined statuses and validation

`app/Enums/TrxStatus.php` defines exactly `PENDING='pending'`, `COMPLETED='completed'`, `CANCELED='canceled'`, and `FAILED='failed'`. `Transaction` casts status to this enum. The base `transactions.status` migration uses enum values `pending`, `completed`, `failed`; later migrations or deployed schema must accommodate `canceled` because application writes it. No transaction status `PROCESSING`, `SUCCESS`, `REFUNDED`, or `REVERSED` is defined in `TrxStatus`. `MobileRechargeStatus`, P2P order status, virtual card status and subscription status are separate enums and do not expand transaction status.

`TransactionService::completeTransaction()` locks the transaction row and only transitions `PENDING → COMPLETED`; it returns for already completed or any other status. It runs success handler inside the same DB transaction. `failTransaction()` locks and only transitions `PENDING → FAILED`; any non-pending status returns. This provides transition validation for these two methods, not for all direct `$transaction->update()` calls. `cancelTransaction()` has no lock/pending guard and can overwrite status and refund repeatedly. Provider classes and services also directly update transaction status (`MobileRechargeService`, Bitnob/card callbacks, some adapters), bypassing central transition checks.

### Type-specific actual pathways (not every type uses central handler)

```text
Deposit:       PENDING ──completeTransaction──> COMPLETED [DepositHandler credits]
               PENDING ──failTransaction──────> FAILED    [notification]
               PENDING/other ──cancel─────────> CANCELED   [refund only if caller requests it]

Withdrawal:    wallet debited + PENDING ──provider/admin complete──> COMPLETED [notify]
               PENDING ──fail──> FAILED [notify; refund behavior depends on caller]
               PENDING ──cancel(refund=true)──> wallet credit + REFUND row + CANCELED

Transfer:      created as COMPLETED paired records, wallet handlers run in SendMoneyController;
               no universal PENDING state machine. Request-money approval uses pending→complete/cancel.

Exchange:      paired records are created/processed in one controller DB transaction;
               wallet effects are direct handler calls, not generic status transitions.

Merchant:      RECEIVE_PAYMENT PENDING ──complete──> COMPLETED [PaymentHandler credits merchant leg;
               wallet customer leg may be charged in checkout path]; PENDING→FAILED possible.
               Merchant IPN is sent after central complete/fail DB transaction commits.

P2P:           transaction type P2P_ESCROW / P2P_RELEASE / P2P_REFUND rows are commonly created
               already COMPLETED inside P2POrderService; order status is its separate state machine.

Card:          CARD_TOPUP / CARD_WITHDRAW transaction rows may move pending→completed/failed via
               provider webhook; callbacks can directly update status as well as central service.

Recharge:      transaction begins PENDING with wallet already debited; provider result directly sets
               PENDING or COMPLETED, or FAILED with a refund. Recharge has its own pending/completed/
               failed enum. It does not always pass through TransactionService handlers.
```

Specific generic terminology requested in prompt: `PROCESSING`, `SUCCESS`, `REFUNDED`, `REVERSED` are **not** transaction statuses found. “Refund” is represented by a separate `REFUND` transaction type with `COMPLETED`, plus original transaction often set `CANCELED`; “reversal” is not a generic transaction state. Some transaction types may be created directly as `COMPLETED` without a pending transition.

## 5. Handler Analysis

The handler directory contains eight concrete handlers plus interfaces. `TransactionService::resolveHandler()` only maps `DEPOSIT`, `RECEIVE_PAYMENT`, `REQUEST_MONEY`, and `WITHDRAW`. Other handlers are invoked by their feature controller/service directly.

| Handler | Trigger and types | Financial effect | Other effects / boundary / risk |
|---|---|---|---|
| `DepositHandler` | Central completion for `DEPOSIT`; fail/submitted from admin/user deposit flow | Credits wallet `net_amount` on success | Referral reward and user/admin notifications; when invoked centrally, handler runs inside DB transaction. Notification channel can execute synchronously; no outbox. Duplicate controlled only by central pending guard. |
| `ExchangeMoneyHandler` | Direct `ExchangeMoneyController` use; `EXCHANGE_MONEY` legs | Debit `MINUS` payable; credit `PLUS` net | User notification on plus leg. No external provider call. Caller's DB transaction is important; handler itself has none/idempotency guard. |
| `GiftCardHandler` | Direct gift-card redeem/cancel service; gift card types | Credits wallet on `PLUS` | Notification; controller/service transaction boundary; repeated direct call can repeat credit absent gift-card state guard. |
| `PaymentHandler` | Central `RECEIVE_PAYMENT` completion; also checkout path | Debit payer on `MINUS`, credit merchant on `PLUS`; no wallet op for sandbox | User notification/logging. No provider call itself. When central, runs in DB tx. Sandbox checks transaction remarks/JSON. Side effect tied to transaction state. |
| `RequestMoneyHandler` | Central completion mapping or direct request approval; request type | Credit `PLUS`, debit `MINUS` | User notifications and linked transaction/user lookups. Boundary depends on caller; direct handler lacks lock/idempotency. |
| `SendMoneyHandler` | Direct send controller; send/receive transaction pair | Debit sender / credit receiver | Notifications. Controller DB tx surrounds paired effects; no provider calls/locks in handler. |
| `VoucherHandler` | Direct voucher redeem/purchase operation | Credits `PLUS` on redemption; voucher purchase debit occurs in controller | User notification likely through notifier. Controller transaction and voucher state checks are key. |
| `WithdrawHandler` | Central complete/fail/submitted for `WITHDRAW` | No wallet effect | Notifications to user/admin. Withdrawal debit happened earlier; refund does not occur here. Central call typically under DB transaction; channel behavior varies. |

Interfaces `SuccessHandlerInterface`, `FailHandlerInterface`, `SubmittedHandlerInterface` declare lifecycle methods only; there is no uniform handler transaction/idempotency contract.

## 6. Deposit Money Creation

| Credit source | Wallet and record | Duplicate/callback/late-event behavior evidenced |
|---|---|---|
| Automatic payment gateway | User selected wallet UUID; pending `DEPOSIT` transaction with generated `trx_id`; `DepositHandler` credits `net_amount` on completion | `trx_id` is generated by `Transaction` model with application-level “exists” loop, but base DB migration does not declare unique `trx_id`. Central completion serializes by transaction row and only accepts pending, so a second completion through this path is ignored. A failed/canceled transaction cannot complete centrally. Provider-specific event ID/payload persistence and ambiguity/reconciliation vary. |
| Manual deposit | User wallet; pending manual `DEPOSIT` transaction; admin approve calls `completeTransaction`, reject calls cancel/fail route | Central completion gives same pending guard. Manual admin authorization follows backend route/permission. No independent external provider reference required. |
| Stablecoin/Bitnob deposit | Wallet resolved from pending transaction/reference by `BitnobDepositService::applyDepositSuccess()`; Bitnob webhook | Bitnob signature middleware can accept all callbacks if webhook secret blank. Controller catches errors and responds 200. No webhook inbox/event ID dedup found; service’s transaction state checks are only operation guard. Late-after-failure policy depends on service status handling. |
| Agent cash-in | Customer wallet is credited; matching agent/cash operation accounting and `AGENT_CASH_IN` transaction records in `AgentOperationService` | DB transactions + locks around involved wallets/operation. Agent operation lifecycle record guards repeat; external cash evidence is not a provider event. |
| Signup bonus | User default wallet via `SignupBonusService`, `SIGNUP_BONUS` transaction | User signup bonus tracking fields/migration and verification listener reduce duplicate awards; not ledger-based. |
| Referral reward | Referrer wallet via `ReferralService`, `REFERRAL_REWARD` transaction | Triggered from deposit success after eligibility check; repeat protection depends on referral reward record/status. Reward and deposit effects do not have a common durable idempotency key. |
| Rank reward | Wallet chosen by `UpdateUserRanking`, `REWARD` transaction | Listener wraps transaction; repeated rank transition behavior depends on rank state/eligibility check. |
| Wallet earn reward/principal return | User’s original wallet, reward/principal transactions | Stake and payout-number records plus locks support idempotency; no ledger backing. |
| Voucher redemption | Redeemer wallet; `VOUCHER`/related transaction | Voucher code/redeemed status guards typical repeated redemption. |
| Gift-card redemption | Recipient wallet; `GIFT_CARD_REDEEM` transaction, gift-card status | Gift card state and lock/transaction control; inspect cancellation races for each route. |
| Admin add balance | Selected wallet; `ADD_BALANCE` transaction | Permission-gated route and transaction record; no dual control or operation idempotency identified. |
| Merchant settlement / card / other system credits | Merchant wallet or user wallet per associated transaction; typically `RECEIVE_PAYMENT`, `CARD_WITHDRAW`, etc. | Flow-specific status guards; no general provider event inbox or accounting control. |

Who “creates” the money: in gateway and system rewards, local application credit is created by the success handler/service. Provider confirmation is an assertion about external settlement; it is not itself the wallet credit. The system has no ledger counterpart/source account posting in the observed schema. If provider reports success ambiguously, the code generally relies on provider callback/status reconciliation and local transaction status; no universal reconciliation workflow was found.

## 7. Money Exit Paths

| Exit | Reserve/debit | Completion/failure/refund/reversal |
|---|---|---|
| Withdrawal | `PaymentService::withdrawMoney()` locks wallet in DB transaction and debits payable amount while creating pending transaction | Automatic provider submitted after local debit; provider-specific response/callback may complete or cancel+refund. Manual admin processing calls transaction status service. No single universal payout idempotency/reference boundary. |
| Transfer | Sender handler debits and receiver handler credits in the same controller transaction; sender record payable amount | Created as completed, not a delayed payout state. No external completion; no reversal process beyond separate manual adjustments/refund patterns. |
| Exchange | Source leg debit and target leg credit through handlers under controller transaction | Paired completed records and conversion metadata; no FX clearing account or unwind operation. |
| Merchant payment | Customer wallet debit for wallet checkout; merchant net credit on receive-payment leg; fee subtracted in calculation | Checkout completion updates transaction(s); merchant callback sent by `TransactionService` after commit. No explicit platform fee/revenue wallet ledger post. Merchant “settlement” is represented by merchant wallet credit, not a settlement batch/account. Refund/chargeback flow not a unified ledger reversal; admin/source-specific cancel can credit wallet. |
| P2P | Seller wallet debited at order creation by principal + seller fee | On release, buyer receives net amount; on cancel/dispute outcome seller or buyer gets credited according to `P2POrderService`; order statuses govern once-only transitions. Amount resides nowhere as a separate balance after debit: economically represented as order/transaction records, not an escrow account. |
| Card top-up | User wallet debited in card service; provider operation follows | Provider callback completes/fails transaction; failure/refund paths provider-specific. On-card balance often provider metadata and not internal ledger. |
| Recharge | User wallet debited before provider request; pending transaction/recharge record | On provider fail/exception `failAndRefund()` credits wallet and creates REFUND row. Ambiguous timeout is caught as exception and treated as failure/refund, even though provider might have fulfilled: reconciliation/idempotency must be added. |
| Subscription | Default wallet debited synchronously during subscribe/upgrade/renewal | Subscription state/transaction recorded in DB transaction. Proration and plan state may include credits; no ledger account/reversal framework. |
| Wallet earn | Stake principal debited; scheduled earnings and optionally principal credited later | Stake lifecycle and payout rows govern. This is a liability/earn product with no accrual account ledger. |
| P2P promotion | User wallet debited by paid promotion amount | Promotion purchase/order record; no platform revenue ledger. |
| Agent cash-out | Customer wallet debit/agent wallet credit or agent side debit/customer credit depending operation; commission legs | Operation service locks wallets and writes AgentOperation/transaction records. External cash handoff is outside DB transaction. |
| Voucher/gift card/card issuance | Product controller debits wallet (purchase/issue/top-up fee) | Product record and transaction created; failure cancellation/refund is product-specific. |
| Admin subtract balance | Direct helper debit from selected wallet | Adjustment transaction records reason/remarks; no dual approval evidenced, generic helper insufficient-balance check has no lock. |
| Fees | Usually deducted from user payable or withheld from recipient net; fee stored on transaction | No dedicated fee/revenue wallet/account found. Fee configuration is admin-managed; no invariant that every fee gets a separate accounting posting. |

## 8. P2P Financial Flow

Money lifecycle is implemented primarily in `app/Services/P2P/P2POrderService.php`:

| Stage | Actual money representation / mutation | Protection and records |
|---|---|---|
| Offer | Offer references seller/buyer user and a wallet; no funds move at offer publication | Offer state controls availability. |
| Order creation | `createFromOffer()` locks offer/method/payment accounts and seller wallet; conditionally decrements seller `balance` by amount + applicable seller fee. Seller spendable balance decreases. | `DB::transaction`; row locks; SQL `balance >= hold`; creates `Order`, completed `P2P_ESCROW` transaction, sets order `trx_id`. |
| Payment pending | Buyer pays seller externally/off-platform using selected payment-account snapshot. Platform records buyer “paid” confirmation/status; no buyer wallet is debited for this fiat transfer. | Order state and timestamps; no provider confirmation. User assertions are not bank settlement evidence. |
| Seller confirmation/release | Service validates actor and order state; credits buyer wallet (net to buyer) and records P2P release transaction/order status. | DB transaction and order/wallet locks in release path; repeat release should be prevented by locked state check. No escrow account posting. |
| Cancel/expiry/refund | Service recredits seller wallet for held amount (fee treatment by path) and records P2P refund transaction; order canceled/expired. | DB transaction, locked order and wallet in shown transitions; expiry through `ExpireP2POrders` command. |
| Dispute | Dispute creation changes order/dispute state; funds remain absent from wallet and represented by order hold metadata. | Dispute/order locks and unique dispute constraint migration; buyer/seller funds remain economically reserved only by prior wallet debit. |
| Admin resolution | Backend `P2PDisputeController` locks dispute and order, invokes service release/refund resolution; credits selected party and updates states/records. | Transaction/locks and terminal-state checks; permission-gated backend route. No dual operator approval or independent ledger record. |

Where funds reside: after hold, they are not in a dedicated platform escrow wallet/account in the schema. They are removed from seller spendable wallet balance, while the order and `P2P_ESCROW` transaction carry principal/fee/reference metadata. This is a software lock by subtraction + state, not double-entry custody accounting.

## 9. Merchant Financial Flow

1. Merchant creates payment by API (`Api\PaymentController::initiatePayment`) or payment link, which creates pending transaction record(s) and checkout URL/token.
2. Customer opens checkout; `MerchantPaymentReceiveController` validates transaction, environment, amount, merchant and payer. Wallet payment paths debit payer wallet; gateway paths wait on provider callback.
3. On completed receive-side transaction, `TransactionService` locks transaction and `PaymentHandler` credits merchant wallet `net_amount` (unless sandbox). For direct wallet checkout, paired customer and merchant records/processing are in merchant controller paths and `PaymentHandler` may apply legs according to `AmountFlow`.
4. Fee is represented on transaction and deducted from net amount in the payment calculation; no platform fee/revenue wallet posting/account is present in the inspected ledger schema (because there is no ledger).
5. `TransactionService::sendMerchantPaymentIPN()` sends signed HMAC payload synchronously after central completion/failure transaction commits; retries are limited/in-process; no durable outbox/delivery table found.
6. Refund is not a coherent merchant refund API/state machine in `/api/v1`; generic `cancelTransaction(refund=true)` credits original `wallet_reference` and writes a REFUND transaction. This does not itself reverse merchant credit and payer debit as a balanced two-sided operation.

Merchant wallet: merchant users are represented through user/wallet associations (merchant records are separate configuration/identity records). Merchant receipts are transaction credits into wallet rows, not a distinct settlement account. No settlement batch/ledger/account table was found. Thus merchant settlement is represented as merchant wallet balance and transaction history.

## 10. Admin Financial Operations

| Capability | Implementation / authorization evidence | Audit / dual control assessment |
|---|---|---|
| Add/subtract user balance | `Backend\UserManageController::updateBalance()` branches to `Wallet::addMoney()` / `subtractMoney()`; permission mapping uses `user-balance-manage`; creates transaction adjustment details | Transaction row is an audit hint, but no append-only operator log, dual approval or idempotency key was evidenced. Generic helper does not lock. |
| Approve/reject manual deposit | `Backend\DepositController` calls transaction complete/cancel; central success handler credits wallet | `TransactionService` records status/remarks and deposit transaction; no dual approval found. Authorization comes from admin route middleware/permission; exact permission should be confirmed in `routes/admin.php` and permission seeders for deployment. |
| Approve/reject withdrawal | `Backend\WithdrawController` actions transition pending withdrawal; `PaymentService`/transaction service may refund on failure/cancel | Admin permissions/remarks available. Wallet refund path needs idempotency; no double-control. |
| Refund/cancel | Generic `TransactionService::cancelTransaction()` has refund flag; provider classes call it for failed payouts; admin transaction controller also cancels linked request rows | No lock/status guard in refund method; refund transaction is recorded but repeated credit is possible. No generic “reverse” posting. |
| Modify transaction | Backend transaction pages/status controls call complete/cancel; transaction fields and metadata are mutable | No immutable financial audit journal or reason-enforced transition model. |
| Modify wallet | User management adjustment; wallet status toggles in `Frontend\WalletController` but not amount; no separate wallet-balance edit form found beyond `updateBalance()` | Amount changes go through helper; status changes can disable spendability. |
| Modify fees | Admin settings, deposit/withdraw methods, P2P settings, card fees, merchant configuration controllers | Settings are mutable configuration; changes not evidenced as versioned fee schedule snapshots in every transaction beyond recorded fee. |
| Modify exchange rates | Currency management and settings/conversion configuration; `CurrencyConversionService` consumes configured values | Rate change authorization is admin-managed; quote snapshot behavior varies by flow. No accounting approval/version journal. |
| Resolve P2P dispute | `Backend\P2P\P2PDisputeController` calls locked order service resolution | Decision/status remarks/evidence persisted; no second approver. |

Dual-control: no four-eyes approval mechanism for financial adjustments/withdrawal/deposit/dispute operations was identified in the inspected implementation.

## 11. Financial Side Effects

| Side effect | Example path | Timing classification |
|---|---|---|
| User/admin notifications | `DepositHandler`, `WithdrawHandler`, `PaymentHandler`, P2P/earn services | Often synchronously invoked by handler/service, sometimes inside DB transaction (`completeTransaction`, wallet earn); channel could enqueue depending notification config. No universal after-commit/outbox policy evidenced. |
| Merchant webhook/IPN | `TransactionService::sendMerchantPaymentIPN()` | After central complete/fail transaction returns (therefore after DB commit); synchronous HTTP with limited in-process retry. Not durable asynchronous outbox. |
| Provider API calls | `PaymentService::processAutomaticWithdrawal()`, `MobileRechargeService` after creating debit transaction; gateway adapter deposit initiation | Synchronous external HTTP after/beside local transaction; external call cannot roll back with local DB. |
| Referral reward | `DepositHandler::handleSuccess()` → `ReferralService` | Invoked inside central transaction handler in complete transaction; reward credit/record may be nested in same transaction connection. No outbox. |
| Rank update/reward | `TransactionUpdated` event after complete path; listener `UpdateUserRanking` DB transaction | Event dispatched after completion transaction; listener execution behavior depends event registration/queue configuration. Reward mutation is separate transaction. |
| Subscription update | `SubscriptionService` debit and activate/update subscription | Synchronous in same transaction for subscription charge/state. |
| Wallet earn | Payout service credits wallet, creates rows, updates stake, notifies | DB transaction encloses financial updates; notifications invoked inside transaction in visible payout loop. |
| P2P state change | Order service changes order/transaction and wallet | Synchronous in same DB transaction; notification calls may also happen before commit. |
| Card provider callbacks | Bitnob webhook updates card meta/status and transaction | Synchronous request/controller; no queue/inbox; event payload sometimes stored into JSON `meta` only. |
| Queue/jobs | `NotifyUsers`, `ProcessSubscriptions`, `ProcessWalletEarnRewards`, P2P expiration commands | Database queue available; feature work may be scheduled/queued, but absence of deployment configuration means actual execution is unverified. |

Most external HTTP and callback behavior is synchronous. There is no generalized after-commit outbox boundary. The appropriate migration design is to commit ledger operation + outbox record together, then process provider/merchant notifications asynchronously and idempotently.

## 12. Idempotency Inventory

| Mechanism | Scope | Database enforcement / limitation |
|---|---|---|
| `PreventDuplicateSubmission` middleware (`prevent.duplicate`) on money routes | Request-level short window | Not a business-operation key; does not reliably deduplicate retries across sessions/devices/proxies. |
| `Transaction::boot()` generated `trx_id` collision loop | Record identifier generation | Application existence check only; base migration has nullable `trx_id` without unique constraint. Not request idempotency. |
| `TransactionService::completeTransaction()` pending guard + `lockForUpdate` | Transaction state completion | Strong local duplicate callback guard when all callers use it; does not protect direct status updates or `cancelTransaction(refund=true)`. |
| `TransactionService::failTransaction()` pending guard + lock | Transaction failure transition | Prevents repeat central failure transition; no event persistence. |
| P2P order/dispute state + row locks | Business state transition | Effective serialization for many P2P actions; not provider event dedupe; no separate ledger journal uniqueness. |
| Wallet earn payout number/reward row check | Per-stake scheduled reward | Lock + check, but database unique constraint must be confirmed; no universal key. |
| AgentOperation rows/status | Agent business-operation-level | Operation record and locks; unique external request enforcement not generally established. |
| Provider external references in transaction `trx_reference`/JSON | Provider/business-reference hints | No general unique DB constraint on provider reference or provider event ID found. |
| Bitnob signature | Authenticity only | Does not establish event uniqueness or replay protection. Blank secret disables verification. |
| Merchant API timestamp/HMAC | Request authentication/replay window | Timestamp freshness plus signature; no idempotency-key persistence or request-hash uniqueness. Same valid signed request may be repeated within allowed tolerance. |
| Merchant IPN retries | Delivery attempt | In-process retries for selected timeout; no durable delivery record or receiver idempotency contract enforced. |
| P2P dispute unique migration | Business state uniqueness | Specific uniqueness only; does not imply money operation idempotency generally. |

Missing platform-wide mechanisms: idempotency key + request hash + result cache scoped to actor/operation; unique provider event inbox keyed by provider/event ID; database-enforced uniqueness for provider references; immutable posting key; durable callback delivery/outbox; duplicate refund prevention; replay timestamps/nonces for every webhook provider.

## 13. Concurrency Inventory

| Operation | Transaction | Row lock | Conditional update | Idempotency | Safe? |
|---|---|---|---|---|---|
| Generic WalletService debit | No internally | No | No; stale model check only | No | **No** under concurrency. |
| Generic WalletService credit | No internally | No | Atomic SQL increment but no duplicate guard | No | **No** for retry/replay; increment avoids lost-update arithmetic but duplicate credit remains. |
| Withdrawal creation | Yes | Wallet `lockForUpdate` | Sufficiency checked under lock before decrement | Request-level only; provider call separate | **Improved local debit**, external payout lifecycle not fully safe. |
| Send-money transfer | Yes | No consistent wallet locks in controller/helper | Precheck outside transaction | Duplicate middleware only | **No**: two competing operations can pass precheck. |
| Request-money acceptance | Yes | No consistent wallet locks evidenced | Handler helper precheck only | Transaction pair/status | **Not established safe** for concurrent requests. |
| Exchange | Yes | No wallet locks through helper | Precheck outside transaction | Request middleware only | **No** under concurrent debits/retry. |
| Merchant wallet payment | Yes in some direct branches; central completion transaction | Transaction row lock centrally, wallet locking varies | Balance checks exist in checkout; not uniform conditional debit | Pending transaction state | **Mixed**; status guard helps, wallet race and two-sided posting contract remain. |
| P2P order hold | Yes | Offer, method, payment accounts, seller wallet | SQL balance >= hold decrement | Locked state/order | **Strong local race controls**, but no ledger/accounting escrow and verify unique external operation. |
| P2P release/refund | Yes | Order + wallet locks in transition methods | State-dependent checks | Order terminal state and lock | **Generally guarded at state layer**, but ledger absent. |
| Agent operations | Yes | Locks customer and agent wallets; helper operations | Some balances validated after lock | AgentOperation lifecycle | **Relatively guarded**; exact operation uniqueness and external cash confirmation remain. |
| Wallet earn stake | Yes | Plan + wallet lock | SQL balance >= amount decrement | Stake record/status | **Good local critical section**, no journal. |
| Wallet earn payout/principal | Yes | Stake + wallet lock | Due/status/payout number checks | Reward record lookup | **Mostly guarded**; uniqueness relies on locking/check and schema. |
| Subscription charge | Yes | User/subscription and wallet lock via `lockedDefaultWallet()` | Balance check under lock | Active subscription check | **Relatively guarded locally**, no payment operation key or ledger. |
| Mobile recharge debit | Yes | No wallet lock; sufficiency check before transaction | No conditional debit in service | Recharge record; no idempotency key | **No** against concurrent spend and provider retry. |
| Mobile recharge failure refund | Yes | No transaction/recharge lock evidenced | In-memory `status !== FAILED` check | Status check | **No** under concurrent callbacks; duplicate refunds possible. |
| Transaction complete/fail | Yes (retry count 3) | Transaction row lock | Pending-only status check | Transaction ID | **Good for status transition path**, not direct provider updates or all downstream effects. |
| Transaction cancellation with refund | Refund sub-transaction only | No source transaction lock | No terminal/pending conditional | None | **No**; repeated refunds possible. |
| Admin balance change | Not established in method | No lock in generic helper | Helper stale check on debit | Transaction row only | **No** against concurrency/duplicate submissions. |
| Merchant API initiation | Yes around transaction create | Merchant auth/rate limit, no idempotency row | Validation only | HMAC/timestamp | **Not idempotent** within valid request period. |

`increment()`/`decrement()` execute atomic SQL arithmetic on the single column, which avoids some lost-update patterns, but they do not by themselves make a debit safe: sufficiency and status must be checked atomically, and logical retries must be deduplicated.

## 14. Legacy Money Flow Diagram

```text
                        HTTP / command / provider event
                                      │
       ┌───────────┬──────────┬───────┼──────────┬───────────┐
       │           │          │       │          │           │
    Deposit     Transfer   Exchange Withdrawal Merchant   P2P / card /
       │           │          │       │          │         recharge/etc.
 PaymentService   Controller Controller PaymentService API/checkout services
       │           │          │       │          │           │
 Provider IPN  paired trx   paired trx transaction  transaction/service
       │        records     records      pending        records/state
       └───────┬───┴───────┬──┴────┬──────┴──────┬──────────┘
               │           │       │             │
   TransactionService     Handlers / direct service code
   complete/fail/cancel      │             │
    (txn lock only)       WalletService   Direct balance writes
                              │          (P2P, agent, earn)
                              └──────┬──────┘
                                     ▼
                           wallets.balance (FLOAT)
                                     │
                                     └── associated mutable transactions
                                         (not debit/credit ledger entries)
```

Important divergence from the generic flow: withdrawal creation, subscriptions, recharge, agent operations, wallet earn, P2P, virtual card issuance, vouchers/gift cards may debit/credit directly in a service/controller and create transactions separately. Thus no single mutation gateway currently dominates all flows.

## 15. Migration Boundary

### A. New authoritative boundary

Introduce a single application-level posting boundary for every financial operation: request/intent command → idempotency record → locked/atomic double-entry journal posting → projection update → outbox event. Provider inbound events need a durable authenticated inbox before they request a posting. The ledger, not existing `transactions` or `wallets.balance`, becomes source of truth.

### B. Existing services that must stop mutating wallets

At minimum: `WalletService`; all `Services\Handlers\*` that call wallet helpers; `PaymentService`; `TransactionService::cancelTransaction`; `P2POrderService`; `P2POfferPromotionService`; `AgentOperationService`; `WalletEarnService`; `SubscriptionService`; `MobileRechargeService`; `ReferralService`; `SignupBonusService`; `UpdateUserRanking`; voucher/gift-card controllers and handlers; virtual-card issuance/topup/withdraw services/controllers; merchant checkout/API/payment-link services; all backend balance adjustment controllers. The goal is a complete source scan with no direct balance writes outside projection updater.

### C. Controllers that can remain largely unchanged

Presentation, validation, route, and authorization parts of `DepositController`, `WithdrawController`, `SendMoneyController`, `RequestMoneyController`, `ExchangeMoneyController`, `PaymentLinkController`, `PaymentLinkCheckoutController`, `MerchantPaymentReceiveController`, P2P controllers, card/recharge/subscription/earn/agent controllers, and admin views can remain if they call new command/application interfaces and stop making financial decisions/mutations themselves.

### D. Handlers to replace

Replace money-writing behavior in `DepositHandler`, `ExchangeMoneyHandler`, `GiftCardHandler`, `PaymentHandler`, `RequestMoneyHandler`, `SendMoneyHandler`, `VoucherHandler`, and refund behavior currently in `TransactionService::cancelTransaction`. `WithdrawHandler` may retain notification mapping but its lifecycle should subscribe to outbox/state events, not be the financial state authority.

### E. Provider adapters reusable

Provider-specific protocol and API code under `app/Services/Payment/*` can be assessed and reused behind new provider capability interfaces: authentication/signature parsing, checkout initiation, provider status query, payout request and response mapping. Do not reuse existing callbacks as authoritative without inbox persistence, event dedupe, amount/currency/reference verification, replay policy and conformance tests. The existing common interface (`deposit`, `handleIPN`) is too narrow to be the future contract.

### F. Historical transaction records

Existing `transactions` rows can be retained read-only for user/admin history and migration/reconciliation. Their IDs, `trx_id`, `trx_reference`, amount, currency, status, JSON metadata and wallet reference can be mapped to new journal operation IDs where evidence allows. Do not reinterpret them as ledger entries or make new money mutations from historical rows. Preserve them with a migration marker/source mapping and avoid changing existing statuses to simulate journal postings.

### G. Legacy functionality to disable before cutover

Disable or route off all direct wallet mutation paths at cutover: wallet helper writes, direct `increment/decrement`, handler-driven balance effects, direct admin balance adjustment, legacy refund/cancel with `refund=true`, old provider callback completion routes, and old checkout/agent/P2P/earn/subscription/card/recharge mutation services. Avoid dual-authoritative writes. Feature flags should ensure each operation has exactly one financial authority, with reconciliation and rollback plan.

## 16. Critical Findings

1. **Broad mutation surface:** direct writes reside in at least eight architectural areas, not only `WalletService`.
2. **Generic debit is race-prone:** stale in-memory sufficiency check plus decrement, no lock/transaction/predicate inside service.
3. **Refund replay flaw:** `TransactionService::cancelTransaction(refund=true)` lacks locked source-state guard, can credit repeatedly.
4. **State handling is inconsistent:** central transition method protects selected flows; other features directly set statuses and several transaction types start completed.
5. **Provider callbacks lack common inbox:** signatures and dedupe vary; Bitnob blank-secret behavior permits unauthenticated events under misconfiguration, while controller returns 200 after errors.
6. **No ledger representation:** P2P escrow, merchant settlement, fees, earn, rewards and agent commission are all represented through wallet deltas plus mutable transaction/domain rows.
7. **Atomic DB wrapper is not enough:** helper methods do not lock; some callers check funds before transaction; external provider calls occur outside local atomicity.
8. **Merchant payout/refund semantics are incomplete:** merchant wallet credit is not a settlement batch; generic refund is one-sided wallet credit and may not reverse both merchant/customer legs.
9. **System credits are money creation surfaces:** signup bonus, referrals, ranking rewards, wallet earn yield, voucher/gift redemption and admin adjustments must all post through the ledger.
10. **Float and mixed scales:** wallet uses float; transaction model casts amounts to float; business flows round at 2 or 8 decimal places inconsistently.

## 17. Recommended Cutover Sequence

1. Inventory production balances, outstanding pending operations, provider references and fees; reconcile opening assets/liabilities and define currency scale/rounding.
2. Build ledger schema/posting API and invariant tests first; include reversal, hold, fee, clearing and platform accounts, operation idempotency and immutable audit metadata.
3. Add durable webhook inbox and merchant outbox; migrate callback verification to fail-closed and event uniqueness before rerouting provider flows.
4. Add wallet projection from ledger and read-only reconciliation against current `wallets.balance`; do not dual write balances without deterministic reconciliation.
5. Migrate one internal transfer flow, then test duplicate/concurrent requests; disable old send-money mutation once ledger path is sole writer.
6. Migrate deposit and withdrawal with provider reconciliation and payout idempotency; explicitly account for pending/failure/refund/ambiguous outcomes.
7. Migrate merchant payment intents, fee postings, refunds and merchant webhook delivery; validate payer/merchant balances and settlement.
8. Rebuild P2P escrow using hold/release/refund ledger postings and migrate every dispute/expiry outcome.
9. Migrate recharge, subscriptions, earn, agents, vouchers, gift cards, rewards, virtual cards and admin adjustments as separately reconciled operations.
10. Keep legacy transaction rows as history, retire direct mutations, run end-to-end reconciliation, then remove legacy mutation code only after all paths and scheduled jobs are proven inactive.

## Evidence Index

- Wallet/schema: `app/Services/WalletService.php`; `app/Models/Wallet.php`; `database/migrations/2024_11_12_040813_create_wallets_table.php`; `database/migrations/2024_11_16_150322_create_transactions_table.php`.
- Status/state: `app/Enums/TrxStatus.php`; `app/Enums/TrxType.php`; `app/Models/Transaction.php`; `app/Services/TransactionService.php`.
- Handlers: every concrete handler and interface under `app/Services/Handlers/`.
- Entry routes: `routes/web.php`, `routes/api.php`, `routes/admin.php`, `routes/console.php`.
- Deposit/withdraw/provider: `app/Services/PaymentService.php`; `app/Http/Controllers/Frontend/DepositController.php`; `WithdrawController.php`; `IPNController.php`; `app/Services/Payment/PaymentGatewayFactory.php`; `app/Http/Controllers/Webhook/BitnobWebhookController.php`; `app/Http/Middleware/VerifyBitnobSignature.php`; `app/Services/Bitnob/BitnobDepositService.php`.
- Transfer/exchange/merchant: `app/Http/Controllers/Frontend/SendMoneyController.php`; `RequestMoneyController.php`; `ExchangeMoneyController.php`; `MerchantPaymentReceiveController.php`; `PaymentLinkCheckoutController.php`; `app/Http/Controllers/Api/PaymentController.php`; `app/Services/PaymentLinkService.php`; `app/Http/Middleware/MerchantApiAuth.php`.
- P2P/agent/earn/subscriptions/recharge: `app/Services/P2P/P2POrderService.php`; `P2POfferPromotionService.php`; `app/Services/AgentOperationService.php`; `app/Services/WalletEarnService.php`; `app/Services/SubscriptionService.php`; `app/Services/MobileRechargeService.php`.
- Other credits/admin: `app/Services/SignupBonusService.php`; `ReferralService.php`; `app/Listeners/UpdateUserRanking.php`; `app/Http/Controllers/Backend/UserManageController.php`; `app/Http/Controllers/Frontend/VoucherController.php`; `GiftCardController.php`; card controllers/services under `app/Services/VirtualCard/` and `app/Http/Controllers/Frontend/VirtualCardController.php`.
