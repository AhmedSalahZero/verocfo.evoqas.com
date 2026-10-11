# خطة معالجة العيوب — Vero CFO

**التاريخ:** 8 سبتمبر 2026
**المصدر:** مخرجات `php artisan demo:seed-testing-cycle --years=2 --start-year=2023`
**نتيجة آخر تشغيل:** 1,667 operation · 4,021 verification · **68 finding** · exit code 1
**التغطية:** 61 domain مغطّى · 2 جزئي · 1 غير مغطّى

> هذه الوثيقة مشتقّة من `AUDIT-REPORT.md` وتعرض الجانب التنفيذي منه فقط:
> ما العيب، وما سببه، وما الحل المقترح، وبأي ترتيب.
> التفاصيل الكاملة وأدلة إعادة الإنتاج موجودة في سجل الـ findings في `AUDIT-REPORT.md` §6.

---

## الخلاصة

**الـ 68 finding ترجع إلى 19 سبباً جذرياً فقط.** الباقي تكرار: العيب نفسه يُسجَّل في كل شهر أو لكل شركة.

| المستوى | العدد | الطبيعة |
|---|---:|---|
| 🔴 إصلاح فوري | 2 | فقدان بيانات فعلي |
| 🟠 إصلاح تقني مباشر | 7 | السبب واضح والحل محدّد |
| 🟡 يتطلب قراراً محاسبياً | 3 | لا يمكن الإصلاح قبل القرار |
| 🟢 إصلاحات صغيرة | 7 | مجموعها أقل من ساعة |

---

## 🔴 المستوى الأول — إصلاح فوري

### 1. المخزون يخرج دون إنشاء Journal Entry — `MFG-005` · تكرر 6 مرات

**السبب**
الدالة `StockLedgerService::issue()` تفتح transaction خاصاً بها **وتُنهيه بـ commit**. بعد ذلك يقوم الـ caller بإنشاء الـ Journal Entry في statement منفصل تماماً. لا يوجد outer transaction يجمع الاثنين.

النتيجة: إذا فشل الـ posting لأي سبب (فترة محاسبية مقفلة، حساب غير محدد)، **يكون المخزون قد خرج فعلياً والقيد غير موجود — بشكل دائم لا رجعة فيه.**

**الحل المقترح**
تغليف العمليتين داخل `DB::transaction()` واحد في `consumeMaterial()`:

```php
// app/Services/Accounting/JobOrderService.php
// app/Services/Accounting/ProductionOrderService.php   ← بها المشكلة نفسها
public function consumeMaterial(...) {
    return DB::transaction(function () {
        $this->stockLedger->issue(...);        // أصبح داخل transaction خارجي
        $this->journalEntryService->post(...); // إذا فشل، تُلغى الحركة معه
    });
}
```

**الملفات المتأثرة:** `JobOrderService.php` و `ProductionOrderService.php` — التعديل نفسه في كليهما.

---

### 2. الأرصدة الافتتاحية لا تصل إلى الـ General Ledger — `OB-001`

**السبب**
الدالة `OpeningBalanceService::post()` تُجري الـ validation، ثم تُغيّر `is_posted` إلى `true`، ثم تعود. أما السطر المسؤول عن إنشاء القيد فهو **مكتوب كـ comment**:

```php
// TODO (Stream 4): Create opening balance journal entry
// $this->journalService->createOpeningEntry($companyId, $actor);
```

النتيجة: شركة تُرحَّل إلى النظام بأصول قيمتها 8 ملايين جنيه ← **صفر Journal Entries**. وتفتح الـ Balance Sheet على صفر.

**الحل المقترح**
إنشاء opening Journal Entry واحد بتاريخ `opening_balance_date`، مديناً بمجموع الـ debit lines ودائناً بمجموع الـ credit lines، عبر `JournalEntryService::post()` المستخدَم في كل موديول آخر في النظام.

> **⚠️ يتطلب قراراً:** هل يكون هذا القيد قابلاً للـ reverse أم لا؟

---

## 🟠 المستوى الثاني — إصلاح تقني مباشر

### 3. خمسة جداول أكواد مستنداتها فريدة على مستوى النظام كله — `TEN-001` · تكرر 13 مرة

**السبب**
الـ unique index مبني على عمود الـ `code` وحده، بينما الكود نفسه يُولَّد بعدّاد **مستقل لكل شركة**. فأول شركة تُنشئ المستند رقم 1 في شهر معيّن تحجز هذا الكود للنظام بأكمله، وأي شركة أخرى **تُرفَض**.

الجداول المتأثرة:

```
customer_debit_notes       customer_debit_notes_debit_note_code_unique
supplier_debit_notes       supplier_debit_notes_debit_note_code_unique
supplier_credit_notes      supplier_credit_notes_credit_note_code_unique
landing_costs              landing_costs_landing_cost_code_unique
internal_settlements       internal_settlements_settlement_code_unique
```

**الحل المقترح**
migration واحدة — والنموذج موجود لديك بالفعل في:

`database/migrations/2026_01_01_000162_fix_remaining_document_code_unique_scopes.php`

وهي التي عالجت المشكلة نفسها للجداول التي كانت موجودة آنذاك. الجداول الخمسة أعلاه أُضيفت بعدها.

```php
$table->dropUnique('customer_debit_notes_debit_note_code_unique');
$table->unique(['company_id', 'debit_note_code']);
```

**حارس منع التكرار:** `tests/Feature/Erp/DocumentCodeScopingTest.php` يقرأ الـ schema مباشرة، فيفشل تلقائياً إذا أُضيف جدول جديد بالخطأ نفسه.

---

### 4. الخطة لا تستطيع استيراد فريق العمل الحالي — `PLN-001` · تكرر 4 مرات

**السبب**
كلمة واحدة. في `PlanManpowerService::snapshotExistingTeam()`:

```php
->where('employee_payroll_profiles.company_id', $plan->company_id)
->where('is_active', true)        // ← العمود موجود في الجدولين
->join('partners', ...)
```

يرفض MySQL الاستعلام: `Column 'is_active' in where clause is ambiguous`.

**الحل المقترح**

```php
->where('employee_payroll_profiles.is_active', true)
```

---

### 5. يستحيل حفظ عملية Buy/Sell Currency — `TRS-003`

**السبب**
العمودان `sell_reference_rate` و `buy_reference_rate` معرَّفان `NOT NULL` بلا default في migration رقم `000141`، والدالة `create()` **لا تضبطهما إطلاقاً** — الدالة `post()` وحدها هي التي تفعل ذلك. كما أن `StoreBuySellCurrencyRequest` لا يحتوي على rule لأيٍّ منهما، فلا سبيل لتمريرهما من الـ UI أصلاً.

وبالمثل تكتب `setToDraft()` قيمة `NULL` في العمودين نفسيهما، فالمسار مغلق من الاتجاهين.

```
SQLSTATE[HY000]: 1364 Field 'sell_reference_rate' doesn't have a default value
```

**الحل المقترح (الأبسط والأأمن)**
migration تجعل العمودين `nullable()`. الموديول بالكامل معطَّل حالياً بسببهما.

---

### 6. التقارير المالية لا تُفلتَر حسب الشركة خارج الـ HTTP request — `TEN-002` · تكرر مرتين

**السبب**
السطر الأول في `CompanyScope`:

```php
if (auth()->check() && app()->bound('currentCompanyId')) {
```

أي أنه خارج الـ web request — في أي artisan command أو queued job أو scheduled task — **لا يطبّق أي فلترة على الإطلاق**. والخدمتان `CustomerBalanceService` و `SupplierBalanceService` تستقبلان `$companyId` **ولا تستخدمانه في أي استعلام**، اعتماداً كاملاً على ذلك الـ scope.

النتيجة المُثبَتة: تقرير عملاء الشركة B أعاد 46,464,931 (مجموع الشركتين) بدلاً من 699,846 الخاصة بها.

**الحل المقترح**
إضافة `->where('company_id', $companyId)` في الخدمتين.

**النموذج الصحيح موجود لديك:** `InvoiceAgingService` في المنطقة نفسها يفعل ذلك بالضبط، وهو سليم في جميع السياقات.

---

### 7. العقد الواحد يُرهَن مرتين لنفس التسهيل — `VAL` · تكرر مرتين

**السبب**
الدالة `ContractAssignmentOdService::addAssignment()` لا تتضمن duplicate check. فيُقرض البنك مرتين مقابل المديونية نفسها، ويصبح الحد المتاح للتسهيل مبالغاً فيه.

**الحل المقترح**
فحص وجود سجل سابق بنفس `(credit_facility_id, collateral_so_id)` قبل الإضافة.

> يُذكر أن الخدمة تفرض قاعدة أخرى بشكل صحيح — رفض ضمان ينتهي قبل تاريخ مراجعة التسهيل — مما يرجّح أن الأمر سهو لا قرار.

---

### 8. الـ Medium-Term Loan غير موجود محاسبياً — `TRS-002` · مرتان (السحب والسداد)

**السبب**
نقطتا الترحيل كلتاهما **مكتوبتان كـ comments**:

```php
// create()          → ACCOUNTING ENGINE TODO #1 — INITIAL LIABILITY
// recordSettlement() → ACCOUNTING ENGINE TODO #2 — INSTALLMENT SETTLEMENT
```

قرض بقيمة 1,200,000 جنيه سُحب وسُدِّد جزئياً — **دون أن يتحرك رصيد البنك، ودون تسجيل أي التزام، ودون قيد مصروف فوائد**.

**الحل المقترح**
اتباع النمط نفسه المستخدَم في الـ LG — جدول `mtl_movements` مقابل `lg_issuance_movements` القائم، والقيود:

```
عند السحب : Dr البنك              /  Cr التزام القرض
عند السداد: Dr التزام القرض  (الأصل)
            Dr مصروف فوائد  (الفائدة)  /  Cr البنك
```

> **⚠️ يتطلب قراراً:** كيفية توزيع الأصل والفائدة في السداد الجزئي — والـ comment نفسه يشير إلى أن هذه النقطة تحتاج تأكيداً.

---

### 9. تسوية المخزون لا تُنشئ Journal Entry — `INV-007` · تكرر مرتين

**السبب**
الدالة `StockLedgerService::adjust()` تُحرّك المخزون فقط. ولا يوجد `source_type = 'stock_adjustment'` في أي موضع من النظام.

> **⚠️ هذا قرار محاسبي لا تقني:** إلى أي حساب يُرحَّل الفرق؟ (عادةً حساب عجز/زيادة مخزون ضمن المصروفات). يلزم تحديد الحساب أولاً قبل كتابة أي كود.

---

## 🟡 المستوى الثالث — يتطلب قراراً محاسبياً قبل التنفيذ

### 10. الـ AR Control Account لا يطابق كشف العملاء — `RPT-001`

**السبب**
الفرق يساوي **بالضبط** مبلغ عمليات الـ Factoring. الخدمة تُخرج الـ receivable من الـ General Ledger وتترك الـ invoice مفتوحة في الـ subledger.

| الحركة على حساب 133 | القيمة |
|---|---:|
| customer_invoice | 2,813,664.00 |
| customer_payment | −2,078,728.00 |
| **factoring_collection** | **−466,944.00** |
| cheque_receivable (ارتداد) | 232,902.00 |
| internal_settlement | −33,380.00 |
| credit_note | −1,710.00 |

### 11. الـ AP Control Account لا يطابق كشف الموردين — `RPT-002`

**السبب**
الأمر نفسه على الجانب الآخر — الـ Reverse Factoring يُصفّي الـ payable في الـ GL ويترك الـ bill مفتوحاً. الفرق يساوي **بالضبط** 117,042.00، أي كامل حركة `reverse_factoring_txn`.

> **⚠️ سؤال واحد يحسم الاثنين معاً:**
>
> **هل تظل الفاتورة التي خضعت لـ factoring (بحق الرجوع) مفتوحة في كشف العملاء، أم تُقفَل؟**
>
> إجابتك تحدد أيّ الطرفين هو الذي يُعدَّل. لا يمكن الإصلاح قبلها.

---

### 12. الميزانية التقديرية في الـ Plan غير متوازنة — `PLN-002` · تكرر 4 مرات

**السبب**
الفرق البالغ 304,588 يظهر منذ **الشهر صفر** — أي أن الخلل في الأرصدة الافتتاحية للخطة لا في حركة الشهور. والمحرك نفسه يُصرّح بذلك في مخرجاته: `is_balanced => false`، **ولا يقرأ أحد هذه القيمة**، والشاشة تعرض المجموع السنوي فقط.

**والأخطر:** تقييم الشركة (الـ DCF والمضاعف معاً) محسوب من هذه الميزانية.

**حل مرحلي منخفض التكلفة جداً**
إظهار تحذير على الشاشة إذا كان أي شهر `is_balanced = false`. هذا يوقف الضرر فوراً ريثما تُراجَع الحسبة نفسها.

---

## 🟢 المستوى الرابع — إصلاحات صغيرة (مجموعها أقل من ساعة)

| # | العيب | السبب | الحل المقترح |
|---|---|---|---|
| 13 | الراتب لا يقبل تاريخ بداية سابقاً — `HR-002` | `EmployeePayrollProfileService::create()` يضبط `effective_date = now()` بقيمة ثابتة | قراءتها من الـ payload |
| 14 | خطأ 500 عند حفظ رصيد افتتاحي — `OB-002` | `$data['amount_foreign']` تُقرأ دون `?? null` | إضافة `?? null` — **سطر واحد** |
| 15 | فورم أمر الإنتاج يطلب BOM تتجاهله الخدمة — `MFG-007` | الحقل ظل `required` بعد تعديل الخدمة لتحلّه من تاريخ الأمر | حذف `bill_of_material_id` من الـ rules ومن الفورم |
| 16 | شاشتا مخزون تعرضان رقمين مختلفين — `RPT-003` | التقرير يجمع من `stock_movements`، بينما الشاشة تقرأ `ProductStockBalance` | توحيد المصدر: القراءة من `ProductStockBalance` |
| 17 | التسهيل الجديد بلا حساب بنكي — `TRS-001` | `CleanOverdraftService::create()` لا يُنشئ `bank_account_id` | إنشاء BankAccount مرتبط بالتسهيل |
| 18 | الـ Factoring يطلب حساباً لا يوفّره الفورم — `TRS-005` | يشترط `ar_account_id` / `ap_account_id` والفورم لا يطلبهما | fallback إلى الحساب الافتراضي كبقية المسارات |
| 19 | رسالة خطأ مضلِّلة في تسوية العملة — `FX-001` | الرسالة تعرض المبلغ بالعملة الوظيفية بينما الحقل يستقبل عملة الفاتورة | **تعديل نص الرسالة فقط** — الحل الأأمن |

---

## الترتيب المقترح للتنفيذ

| الأسبوع | المهام | المبرر |
|---|---|---|
| **الأول** | 14 · 4 · 19 · 15 + migration رقم 3 | مجموعها أقل من يوم عمل، وتُغلق 16 finding |
| **الثاني** | 1 · 2 (بعد القرار) | الأخطر — فقدان بيانات فعلي |
| **الثالث** | 6 · 7 · 5 | ثغرات أمان وموديول معطَّل بالكامل |
| **الرابع** | 8 · 9 + قرار السؤال في 10/11 | تتطلب قرارات محاسبية |

---

## كيفية التحقق بعد كل إصلاح

```bash
php artisan demo:seed-testing-cycle --years=2 --start-year=2023
```

يُفترض أن يختفي العيب من القائمة وينخفض عدد الـ findings. هذا هو الدليل العملي على أن الإصلاح نجح فعلاً.

الأمر **حتمي (deterministic)**: تشغيلتان متتاليتان تُنتجان النتيجة نفسها حرفياً، فأي فرق بين تشغيلة وأخرى يعود إلى تغييرك أنت لا إلى عشوائية في الأداة.

كما أنه **يحذف شركاته الثلاث ويعيد بناءها في كل تشغيل**، ويحذف بالاسم الكامل لا بالـ id — فلا يمس شركاتك الحقيقية إطلاقاً.

---

## القرارات المطلوبة منك

هذه القرارات تحجب إصلاحات بعينها، ولا يمكن تخمينها:

| # | القرار | يفتح |
|---|---|---|
| 1 | هل تظل الفاتورة المخصومة (factored) مفتوحة في الـ subledger؟ | 10 · 11 |
| 2 | إلى أي حساب تُرحَّل فروق تسوية المخزون؟ | 9 |
| 3 | كيف يُوزَّع الأصل والفائدة في السداد الجزئي للقرض؟ | 8 |
| 4 | هل يكون القيد الافتتاحي قابلاً للـ reverse؟ | 2 |

---

## ملاحظة على نطاق العمل

**لم يُصلَح أيٌّ من هذه العيوب.** الـ boundary المتفق عليه ينص على الاكتشاف والتبليغ دون تعديل في منطق المحاسبة. والعدّادات لا تزال:

```
Accounting Logic Modified:     0
Financial Equations Modified:  0
Journal Logic Modified:        0
Closing Logic Modified:        0
```

الاستثناء الوحيد هو `SEED-002` — توقيع دالة خاطئ في الـ demo seeders، وهو أداة اختبار لا منطق محاسبي.

---

## مستندات ذات صلة

| الملف | المحتوى |
|---|---|
| `AUDIT-REPORT.md` | التقرير الكامل — 18 قسماً، وسجل الـ findings الأربعين بأدلة إعادة الإنتاج |
| `USE-CASE-REGISTRY.md` | كل الـ endpoints التي تُغيّر حالة (435)، وما يختبرها، وما يحرسها |
| `tests/Erp/README.md` | إعداد وتشغيل الـ phpunit suite |
