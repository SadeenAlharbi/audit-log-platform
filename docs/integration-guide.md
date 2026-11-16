# دليل الربط · Integration Guide

يشرح هذا الدليل كيف يرسل أي نظام سجلات التدقيق إلى المنصة. العقد الرسمي الكامل هو [openapi.yaml](openapi.yaml)، ويُعرض في المنصة على `/docs/api`. ونفس الأمثلة موجودة داخل لوحة التحكم في صفحة كل مشروع (قسم «دليل الربط») مع الـ slug والرابط الصحيحين.

## 1. طبقتا الربط

| الطبقة | لمن | ملاحظة |
|---|---|---|
| **REST API** | أي نظام بأي لغة | الطريقة الرسمية الوحيدة، وكل ما تقبله المنصة معرَّف في OpenAPI |
| **حزمة `company/audit-client`** | مشاريع Laravel 11 / 12 / 13 على PHP 8.2+ | غلاف خفيف فوق الـ API، لا يحتوي منطقًا خاصًا بالمنصة. كل ما تفعله الحزمة ممكن عبر الـ API مباشرة |

## 2. ربط مشروع جديد (Onboarding)

1. **لوحة التحكم ← المشاريع ← إنشاء**: يُولَّد الـ slug ومفتاح API تلقائيًا.
2. يُعرض المفتاح (وسر التوقيع إن وُجد) **مرة واحدة فقط**. انسخه إلى خزنة الأسرار في النظام المرسِل. المنصة تحفظ SHA-256 للمفتاح فقط.
3. أنشئ مفاتيح منفصلة لكل بيئة (`production` و`staging`) من تبويب «المفاتيح».
4. افتح المشروع واضغط **«إرسال سجل تجريبي»** للتأكد أن مسار التخزين يعمل.
5. أرسل أول سجل من النظام، ثم تأكد من ظهوره في **سجلات التدقيق**، أو من «آخر حدث مستلم» في صفحة المشروع.

### المفاتيح

| الخاصية | التفاصيل |
|---|---|
| الصيغة | `alh_` متبوعة بـ 44 حرفًا عشوائيًا. أول 8 أحرف تظهر في اللوحة للتمييز |
| الصلاحيات (scopes) | `logs:write` للإرسال، و`logs:read` للاستعلام. الجمع بينهما في مفتاح واحد يحتاج قرارًا صريحًا عند الإنشاء |
| الصلاحية الزمنية | 90 يومًا، مع تنبيه قبل الانتهاء بـ 14 يومًا |
| التدوير | ينشئ مفتاحًا جديدًا بنفس الإعدادات، ويبقى القديم صالحًا 24 ساعة ثم ينتهي |
| الإلغاء | فوري |
| قائمة IP | اختيارية، وتقبل عناوين IPv4/IPv6 مفردة أو نطاقات CIDR |
| التوقيع | المفاتيح المنشأة مع «سر توقيع» يجب أن توقّع كل طلب (القسم 4) |

## 3. الطلب

```
POST /api/v1/logs              سجل واحد
POST /api/v1/logs/batch        حتى 500 سجل: {"logs":[ ... ]}
GET  /api/v1/logs              استعلام بفلاتر (scope logs:read)
GET  /api/v1/logs/{uid}        سجل واحد
GET  /api/v1/batches/{uid}     حالة دفعة
GET  /api/v1/health            فحص الجاهزية (بدون مصادقة)
```

**الترويسات:**

```
Authorization: Bearer <api_key>
X-Project: <slug>
Content-Type: application/json
X-Timestamp / X-Signature      للمفاتيح الموقَّعة فقط
X-Request-Id                   اختياري، ويُعاد في الرد
```

**جسم السجل:**

| الحقل | إلزامي | ملاحظة |
|---|---|---|
| `event` | نعم | مثل `user.updated` أو `order.paid` |
| `action` | نعم | create / update / delete / login / logout / custom… |
| `occurred_at` | نعم | ISO-8601 بأي منطقة زمنية، ويُخزَّن UTC. لا يُقبل أقدم من مدة الاحتفاظ، ولا أبعد من 5 دقائق في المستقبل |
| `external_id` | يُنصح به | مفتاح منع التكرار (القسم 5) |
| `severity` | لا | info (الافتراضي) / warning / critical |
| `actor` | لا | `{type, id, name, ip, user_agent}` |
| `subject` | لا | `{type, id}` |
| `old_values`, `new_values`, `metadata` | لا | كائنات JSON |
| `tags` | لا | حتى 20 وسمًا |
| `request_id`, `correlation_id` | لا | لربط الأحداث المتسلسلة |

أي حقل غير معرَّف يُرفض (`validation`). الحد الأقصى لحجم الطلب 1MB.

**مستوى الخطورة (`severity`):** يحدده النظام المرسل لكل حدث، لأنه أعلم بأثره. الأساس: أثر الإجراء على سرية البيانات وسلامتها وتوافرها، وهل يحتاج من يراجعه أن يتابعه (القرار D20):

| المستوى | متى يُستخدم | أمثلة | أثره في المنصة |
|---|---|---|---|
| `info` (معلومة) | إجراء اعتيادي ضمن سير العمل، لا يحتاج متابعة | إنشاء أو تعديل بيانات عادية، دخول ناجح، عرض، تصدير اعتيادي | القيمة الافتراضية إن لم يُرسل المستوى |
| `warning` (تحذير) | إجراء مشروع لكنه حساس أو غير معتاد، يستحق المراجعة | محاولة دخول فاشلة، تعديل بيانات مالية أو شخصية (حساب بنكي، راتب)، رفض طلب، تصدير كبير | لون برتقالي في الجدول والرسم، ويمكن تصفيته |
| `critical` (حرج) | إجراء يمس الصلاحيات أو الأمن، أو قد يسبب ضررًا جسيمًا، ويحتاج مراجعة فورية | منح دور أو صلاحية أو سحبها، تعطيل ضابط أمني، حذف جماعي، تغيير إعدادات الدفع | يُعدّ في مؤشر «أحداث حرجة اليوم»، ولون أحمر في الجدول والرسم |

حزمة `company/audit-client` ترسل `info` افتراضيًا، و`warning` لمحاولات الدخول الفاشلة تلقائيًا. غيّر المستوى لحدث معيّن بـ `->severity(Severity::Critical)`.

**الرد:**

```json
202  {"data":{"uid":"01JCDE0Z8Y2K7Q4M9V3N5T6W8X","status":"accepted"}}
409  {"error":{"code":"duplicate","message":"…","details":{"uid":"<uid الأصلي>","external_id":"…"}}}
```

السجل يُخزَّن عبر طابور خلال ثوانٍ، و`GET /logs/{uid}` يعيد 404 حتى يُخزَّن.

## 4. توقيع الطلبات (HMAC-SHA256)

```
canonical   = X-Timestamp + "\n" + METHOD + "\n" + path?query + "\n" + raw body
X-Signature = hex( HMAC-SHA256( secret, canonical ) )
```

- `X-Timestamp`: وقت يونكس بالثواني. يُرفض الطلب إذا ابتعد عن ساعة الخادم أكثر من 5 دقائق.
- `path?query`: كما أُرسل تمامًا، مثل `/api/v1/logs?event=user.login`.
- الجسم يُوقَّع **كما هو** (بايت ببايت). لا تُعِد ترميز JSON بعد التوقيع.
- لكل GET جسم فارغ، فيُوقَّع سطر فارغ في النهاية.
- لا يمكن إعادة استخدام التوقيع نفسه مرتين (منع Replay).

## 5. منع التكرار (Idempotency)

أرسل `external_id` ثابتًا لكل حدث، مثل `order-1001-paid` أو ULID يُولَّد مرة واحدة عند وقوع الحدث. لو أُعيد الإرسال بسبب انقطاع الشبكة، يرد الخادم بـ `409 duplicate` ومعه `uid` السجل الأصلي. **اعتبر `409 duplicate` نجاحًا**.

في الدفعات لا يُفشل التكرار الدفعة كلها: لكل عنصر حالة `accepted` أو `duplicate` أو `rejected` مع أخطائه.

## 6. البيانات الحساسة

- **لا تُخزَّن أبدًا:** المفاتيح التي تحمل كلمات مرور أو رموزًا أو بيانات دفع (`password`، `token`، `secret`، `authorization`، `card_number`، `cvv`…) تُستبدل بـ `[REDACTED]` قبل التخزين، أينما كانت في الـ JSON.
- **تُخفى عند العرض:** البيانات الشخصية (`national_id`، `iban`، `passport`…) تظهر `********` في الـ API والتصدير واللوحة. لا يكشفها إلا من يملك صلاحية `logs.view_sensitive`.

الأفضل ألا يرسل النظام قيمًا حساسة أصلًا. والحزمة تخفيها قبل الإرسال.

## 7. الأخطاء

كل الأخطاء بنفس الصيغة:

```json
{ "error": { "code": "validation", "message": "…", "details": { "event": ["…"] } } }
```

| الكود | HTTP | ماذا تفعل |
|---|---|---|
| `validation` | 422 | خطأ في البيانات. لا تُعِد المحاولة، وأصلح الحقل |
| `unauthorized` | 401 | مفتاح أو مشروع أو توقيع غير صحيح، أو مفتاح منتهٍ أو ملغى |
| `forbidden` | 403 | المفتاح بلا الـ scope المطلوب، أو IP غير مسموح، أو مشروع مؤرشف، أو اتصال بغير HTTPS |
| `duplicate` | 409 | السجل وصل سابقًا، فاعتبره نجاحًا |
| `payload_too_large` | 413 | الطلب أكبر من 1MB، فقسّمه إلى دفعات أصغر |
| `unsupported_media_type` | 415 | الجسم ليس JSON |
| `rate_limited` | 429 | تجاوزت 1000 طلب في الدقيقة. انتظر حسب `Retry-After` |
| `server_error` | 5xx | أعد المحاولة مع تأخير متزايد |

كل محاولة مرفوضة تُسجَّل في «المحاولات المرفوضة»، ويُرسَل تنبيه عند تكرارها من نفس العنوان.

## 8. أمثلة

السجل المستخدم في الأمثلة:

```json
{"external_id":"order-1001-paid","event":"order.paid","action":"update","severity":"info",
 "occurred_at":"2026-11-11T10:00:00Z","actor":{"type":"user","id":"42","name":"Sara"},
 "subject":{"type":"order","id":"1001"},"old_values":{"status":"pending"},"new_values":{"status":"paid"}}
```

### cURL

```bash
BODY='{"external_id":"order-1001-paid","event":"order.paid","action":"update","occurred_at":"2026-11-11T10:00:00Z"}'
TS=$(date +%s)
SIG=$(printf '%s\n%s\n%s\n%s' "$TS" "POST" "/api/v1/logs" "$BODY" | openssl dgst -sha256 -hmac "$AUDIT_HMAC_SECRET" -hex | sed 's/^.* //')

curl -X POST https://audit.example.com/api/v1/logs \
  -H "Authorization: Bearer $AUDIT_API_KEY" -H "X-Project: erp" \
  -H "X-Timestamp: $TS" -H "X-Signature: $SIG" \
  -H "Content-Type: application/json" -d "$BODY"
```

### PHP (Guzzle)

```php
$body = json_encode($log, JSON_UNESCAPED_UNICODE);
$ts = (string) time();
$sig = hash_hmac('sha256', "$ts\nPOST\n/api/v1/logs\n$body", getenv('AUDIT_HMAC_SECRET'));

(new GuzzleHttp\Client(['timeout' => 5]))->post('https://audit.example.com/api/v1/logs', [
    'headers' => [
        'Authorization' => 'Bearer '.getenv('AUDIT_API_KEY'),
        'X-Project' => 'erp',
        'X-Timestamp' => $ts,
        'X-Signature' => $sig,
        'Content-Type' => 'application/json',
    ],
    'body' => $body,
]);
```

### JavaScript / Node.js (18+)

```js
import crypto from 'node:crypto';

const body = JSON.stringify(log);
const ts = Math.floor(Date.now() / 1000).toString();
const sig = crypto.createHmac('sha256', process.env.AUDIT_HMAC_SECRET)
  .update(`${ts}\nPOST\n/api/v1/logs\n${body}`).digest('hex');

const res = await fetch('https://audit.example.com/api/v1/logs', {
  method: 'POST',
  headers: {
    Authorization: `Bearer ${process.env.AUDIT_API_KEY}`,
    'X-Project': 'erp', 'X-Timestamp': ts, 'X-Signature': sig,
    'Content-Type': 'application/json',
  },
  body,
});
if (res.status !== 202 && res.status !== 409) throw new Error(await res.text());
```

### Python

```python
import hashlib, hmac, json, os, time, requests

body = json.dumps(log, separators=(",", ":"), ensure_ascii=False)
ts = str(int(time.time()))
msg = f"{ts}\nPOST\n/api/v1/logs\n{body}".encode()
sig = hmac.new(os.environ["AUDIT_HMAC_SECRET"].encode(), msg, hashlib.sha256).hexdigest()

r = requests.post("https://audit.example.com/api/v1/logs", data=body.encode(), timeout=5, headers={
    "Authorization": f"Bearer {os.environ['AUDIT_API_KEY']}", "X-Project": "erp",
    "X-Timestamp": ts, "X-Signature": sig, "Content-Type": "application/json",
})
assert r.status_code in (202, 409), r.text
```

### Laravel (`company/audit-client`)

```bash
composer require company/audit-client
php artisan vendor:publish --tag=audit-config
php artisan vendor:publish --tag=audit-migrations && php artisan migrate   # جدول audit_outbox
```

```dotenv
AUDIT_ENDPOINT=https://audit.example.com/api/v1
AUDIT_PROJECT=erp
AUDIT_API_KEY=alh_...
AUDIT_HMAC_SECRET=...
```

```php
// سجل مباشر
Audit::log('order.paid', $user, $order, ['status' => 'pending'], ['status' => 'paid']);

// تسجيل تلقائي لـ created / updated / deleted
class Order extends Model { use \Company\AuditClient\Concerns\Auditable; }
```

الحزمة:
- ترسل من الطابور مع إعادة محاولة بتأخير متزايد (10 ثم 30 ثم 90 ثم 270 ثم 810 ثانية).
- تجمّع السجلات في دفعات.
- توقّع الطلبات، وتخفي الحقول الحساسة قبل الإرسال.
- عند الفشل النهائي تحفظ السجلات في `audit_outbox`. أمر `php artisan audit:flush` يعيد إرسالها.

التفاصيل في README الحزمة.

## 9. الاستعلام (`logs:read`)

```
GET /api/v1/logs?event=user.*&severity=warning,critical&actor_id=42&from=2026-11-01T00:00:00Z&to=2026-11-11T23:59:59Z&per_page=50
```

- النتائج من الأحدث إلى الأقدم، مع ترقيم بالمؤشر (`meta.next_cursor` يُمرَّر كـ `cursor`).
- بدون `from` و`to` تُعاد آخر 30 يومًا. أقصى نطاق 400 يوم.
- كل مفتاح يرى سجلات مشروعه فقط.

## 10. توليد SDK للغات أخرى

من ملف OpenAPI مباشرة، مثلًا:

```bash
npx @openapitools/openapi-generator-cli generate -i docs/openapi.yaml -g python -o sdk-python
```

لا يحتاج ذلك أي تغيير في المنصة. ويبقى التوقيع (القسم 4) مسؤولية الكود المرسِل.
