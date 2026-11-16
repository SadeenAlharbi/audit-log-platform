{{--
    2FA codes depend on the time. If this server's clock differs from the
    viewer's device by more than 90 seconds (Docker Desktop's clock drifts
    after the Mac sleeps), every code is refused although it is right:
    say so instead of leaving the user guessing.
--}}
<div id="alh-clock-warning" role="alert" hidden
     style="position:sticky;top:0;z-index:60;background:#fbebeb;color:#8a1c1c;border-bottom:1px solid #f1c4c4;padding:10px 16px;font-size:14px;line-height:1.6;text-align:center"></div>
<script>
    (function () {
        var serverMs = {{ (int) floor(microtime(true) * 1000) }};
        var diff = Date.now() - serverMs;
        if (Math.abs(diff) < 90000) { return; }
        var minutes = Math.round(Math.abs(diff) / 60000);
        var el = document.getElementById('alh-clock-warning');
        el.textContent = 'ساعة الخادم ' + (diff > 0 ? 'متأخرة' : 'متقدمة') + ' عن ساعة جهازك بحوالي ' + minutes
            + ' دقيقة، فرموز تطبيق المصادقة سترفض وهي صحيحة. أعد تشغيل Docker Desktop (أو ضبط ساعة الخادم) ثم حدّث الصفحة.';
        el.hidden = false;
    })();
</script>
