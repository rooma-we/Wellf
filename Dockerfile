# Dockerfile — WelfVita Gateway (PHP) برای Railway و Wasmer Edge
# یک پروسه‌ی اصلی: ریلی Workerman (پورت عمومی) + سرور پنل PHP داخلی (پورت خصوصی)
FROM php:8.3-cli-alpine

# اکستنشن‌های لازم برای Workerman (pcntl/posix) و سوکت‌ها + sodium (داخلی PHP 8.3 فعال است)
RUN docker-php-ext-install pcntl posix sockets

WORKDIR /app
COPY . .

RUN mkdir -p /app/data

# پورت عمومی: 8080 (استاندارد Wasmer؛ Railway هم با PORT=8080 همین را می‌خواهد)
ENV GATEWAY_PORT=8080 \
    PANEL_INTERNAL_PORT=8081 \
    DATA_DIR=/app/data

# توجه: دستور VOLUME در Railway پشتیبانی نمی‌شود — برای ماندگاری state در Railway
# از Settings → Volumes و mount روی /app/data استفاده کنید (اختیاری؛ بدون آن داده‌ها
# بین دیپلوی‌ها ریست می‌شوند اما پنل کار می‌کند).
EXPOSE 8080

# سرور پنل داخلی پس‌زمینه + ریلی در پیش‌زمینه (ریلی، مسیرهای پنل را پروکسی می‌کند)
CMD ["sh", "-c", "php -S 127.0.0.1:${PANEL_INTERNAL_PORT} index.php & sleep 1 && exec php gateway/gateway.php start"]
