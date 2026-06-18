# WP SMS Panel — پنل پیامک وردپرس

افزونه‌ی عمومی پنل پیامک برای وردپرس: اتصال به درگاه‌های پیامک ایرانی، ورود/ثبت‌نام با کد یک‌بارمصرف (OTP) روی صفحه‌ی ورود وردپرس، شورت‌کد فرم، و یک API ساده برای ارسال پیامک از کد. قابل استفاده در هر پروژه‌ی وردپرسی.

A general-purpose WordPress SMS plugin: connects to Iranian SMS gateways, adds phone-number OTP login/registration to the WordPress login page, a shortcode form, and a simple send API. Portable across any WordPress project.

## امکانات

- **۹ درگاه پیامک** با معماری interface + adapter (افزودن درگاه جدید آسان است)
- **ورود/ثبت‌نام با موبایل (OTP)** روی `wp-login.php` و از طریق شورت‌کد
- **تنظیمات آسان**: انتخاب درگاه فعال + نمایش پویای فیلدهای همان درگاه، تست ارسال، استعلام اعتبار
- **هماهنگی رنگ با تم**: رنگ اصلی، متن دکمه، پس‌زمینه کارت/فیلدها، بوردر و گردی گوشه‌ها
- امنیت استاندارد وردپرس: nonce، capability check، sanitize/escape، Settings API

## درگاه‌های پشتیبانی‌شده

کاوه‌نگار (Kavenegar)، SMS.ir، ملی‌پیامک (Melipayamak)، قاصدک (Ghasedak)، فراز‌اس‌ام‌اس / IPPanel، پارس‌گرین (Parsgreen)، آموت‌اس‌ام‌اس (AmootSMS)، مدیانا (Mediana) — به‌علاوه حالت توسعه (dev) برای تست لوکال بدون ارسال واقعی.

> توجه: مشخصات API درگاه‌های کاوه‌نگار، SMS.ir، ملی‌پیامک، قاصدک و IPPanel با اطمینان بالا پیاده شده‌اند. برای پارس‌گرین، آموت‌اس‌ام‌اس و مدیانا، endpoint و نام فیلدها را پیش از استفاده‌ی واقعی با مستندات پنل خود بررسی کنید (در ابتدای فایل هر adapter یادداشت شده است).

## نصب

1. پوشه‌ی `wp-sms-panel` را در `wp-content/plugins/` قرار دهید.
2. از پیشخوان وردپرس، افزونه را فعال کنید.
3. به منوی **«پنل پیامک»** بروید، درگاه و اعتبار آن را وارد کنید، رنگ‌ها و تنظیمات OTP را تنظیم کنید و ذخیره کنید.
4. با دکمه‌ی **ارسال تست** یک پیامک واقعی بفرستید تا اتصال درگاه تأیید شود.

## استفاده

**شورت‌کد فرم ورود/ثبت‌نام:**

```
[wp_sms_panel_form button_text="ورود" accent="#2563eb" placeholder="09xxxxxxxxx"]
```

**ارسال پیامک از کد:**

```php
if ( function_exists( 'wp_sms_panel_send' ) ) {
    $result = wp_sms_panel_send( '09123456789', 'متن پیام شما' );
    if ( is_wp_error( $result ) ) {
        error_log( $result->get_error_message() );
    }
}
```

**صفحه‌ی ورود وردپرس:** با فعال بودن گزینه‌ی «ورود با موبایل»، فرم OTP بالای فرم پیش‌فرض `wp-login.php` نمایش داده می‌شود (فرم نام‌کاربری/رمز به‌عنوان جایگزین باقی می‌ماند).

## افزودن درگاه سفارشی

از اکشن `wp_sms_panel_register_providers` و کلاس پایه‌ی `WP_SMS_Panel_Provider` برای افزودن adapter دلخواه استفاده کنید.

## مجوز

GPL v2 or later.
