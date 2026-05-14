# Facebook Pixel Event Logger

ชุดไฟล์นี้ใช้เก็บ event จาก GTM ลง MySQL ด้วย PHP

## Event ที่รับ

- ViewContent
- Lead
- CompleteRegistration
- InitiateCheckout
- Purchase

## วิธีติดตั้ง

1. สร้างตารางด้วยไฟล์ `database/schema.sql`
2. อัปโหลด `public/fb-event-track.php` ไปไว้บน server
3. แก้ค่าใน `public/fb-event-track.php`
   - `YOUR-WEBSITE-DOMAIN.com`
   - `YOUR_DATABASE_NAME`
   - `YOUR_DATABASE_USER`
   - `YOUR_DATABASE_PASSWORD`
4. แก้ URL ใน `gtm/fb-event-to-mysql.html`
5. เอาโค้ดใน `gtm/fb-event-to-mysql.html` ไปใส่ใน GTM เป็น Custom HTML Tag
6. ตั้ง Trigger ให้ยิงกับ event:
   - ViewContent
   - Lead
   - CompleteRegistration
   - InitiateCheckout
   - Purchase

## ตัวอย่าง dataLayer

```html
<script>
window.dataLayer = window.dataLayer || [];
window.dataLayer.push({
  event: "Purchase",
  value: 1590,
  currency: "THB",
  content_ids: ["SKU-001", "SKU-002"]
});
</script>
```

## หมายเหตุ

ควรเปลี่ยน `Access-Control-Allow-Origin` ให้เป็น domain จริงของเว็บเท่านั้น อย่าใช้ `*` ใน production
