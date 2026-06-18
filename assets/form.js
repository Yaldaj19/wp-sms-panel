/* WP SMS Panel — OTP form flow (multi-box code, auto-advance, paste, auto-submit) */
(function ($) {
    "use strict";

    function ajax(action, data) {
        return $.post(WPSMSPanel.ajaxurl, $.extend({ action: action, nonce: WPSMSPanel.nonce }, data));
    }

    function setLoading($btn, on) {
        $btn.toggleClass("rs-loading", !!on).prop("disabled", !!on);
    }

    function setMessage($form, text, type) {
        $form.find(".rs-message").removeClass("rs-error rs-ok").addClass(type ? "rs-" + type : "").text(text || "");
    }

    function boxes($form) {
        return $form.find(".rs-otp-box");
    }

    function collect($form) {
        var code = "";
        boxes($form).each(function () { code += (this.value || "").replace(/\D/g, ""); });
        $form.find(".rs-code").val(code);
        return code;
    }

    /* ---- Step 1: send code ---- */
    $(document).on("click", ".wpsp-form .rs-send", function () {
        var $form = $(this).closest(".wpsp-form");
        var $btn = $(this);
        var phone = ($form.find(".rs-phone").val() || "").trim();
        setMessage($form, "");

        if (!/^0?9\d{9}$/.test(phone.replace(/\D/g, ""))) {
            setMessage($form, WPSMSPanel.i18n.invalidPhone, "error");
            return;
        }

        setLoading($btn, true);
        ajax("wp_sms_panel_send", { phone: phone })
            .done(function (res) {
                if (res && res.success) {
                    $form.find(".rs-step-phone").attr("hidden", true);
                    $form.find(".rs-step-code").removeAttr("hidden");
                    $form.find(".rs-phone-echo").text(phone);
                    setMessage($form, res.data.message || WPSMSPanel.i18n.sent, "ok");
                    startTimer($form, res.data.ttl || 120);
                    boxes($form).val("");
                    if (WPSMSPanel.isDev && res.data.dev_code) {
                        fillCode($form, String(res.data.dev_code));
                        $form.find(".rs-message").append(" (DEV: " + res.data.dev_code + ")");
                    } else {
                        boxes($form).eq(0).focus();
                    }
                } else {
                    setMessage($form, (res.data && res.data.message) || WPSMSPanel.i18n.error, "error");
                }
            })
            .fail(function () { setMessage($form, WPSMSPanel.i18n.error, "error"); })
            .always(function () { setLoading($btn, false); });
    });

    /* ---- Step 2: verify ---- */
    function verify($form) {
        var $btn = $form.find(".rs-verify");
        var phone = ($form.find(".rs-phone").val() || "").trim();
        var code = collect($form);
        setMessage($form, "");

        if (code.length < boxes($form).length) {
            setMessage($form, WPSMSPanel.i18n.enterCode, "error");
            return;
        }

        setLoading($btn, true);
        ajax("wp_sms_panel_verify", { phone: phone, code: code, redirect: window.location.href })
            .done(function (res) {
                if (res && res.success) {
                    setMessage($form, WPSMSPanel.i18n.success || "", "ok");
                    window.location.href = res.data.redirect;
                } else {
                    setMessage($form, (res.data && res.data.message) || WPSMSPanel.i18n.error, "error");
                    boxes($form).val("").eq(0).focus();
                    $form.find(".rs-code").val("");
                    setLoading($btn, false);
                }
            })
            .fail(function () {
                setMessage($form, WPSMSPanel.i18n.error, "error");
                setLoading($btn, false);
            });
    }

    $(document).on("click", ".wpsp-form .rs-verify", function () {
        verify($(this).closest(".wpsp-form"));
    });

    /* ---- OTP box behavior ---- */
    function fillCode($form, code) {
        var $b = boxes($form);
        code.replace(/\D/g, "").split("").slice(0, $b.length).forEach(function (ch, i) {
            $b.eq(i).val(ch);
        });
        collect($form);
        var filled = code.replace(/\D/g, "").length;
        $b.eq(Math.min(filled, $b.length - 1)).focus();
        if (filled >= $b.length) { verify($form); }
    }

    $(document).on("input", ".wpsp-form .rs-otp-box", function () {
        this.value = (this.value || "").replace(/\D/g, "").slice(-1);
        var $form = $(this).closest(".wpsp-form");
        var $b = boxes($form);
        var idx = $b.index(this);
        if (this.value && idx < $b.length - 1) {
            $b.eq(idx + 1).focus();
        }
        var code = collect($form);
        if (code.length >= $b.length) { verify($form); }
    });

    $(document).on("keydown", ".wpsp-form .rs-otp-box", function (e) {
        var $form = $(this).closest(".wpsp-form");
        var $b = boxes($form);
        var idx = $b.index(this);
        if (e.key === "Backspace" && !this.value && idx > 0) {
            $b.eq(idx - 1).focus().val("");
            collect($form);
        } else if (e.key === "ArrowLeft" && idx > 0) {
            $b.eq(idx - 1).focus();
        } else if (e.key === "ArrowRight" && idx < $b.length - 1) {
            $b.eq(idx + 1).focus();
        }
    });

    $(document).on("paste", ".wpsp-form .rs-otp-box", function (e) {
        var data = (e.originalEvent || e).clipboardData;
        if (!data) { return; }
        var text = data.getData("text") || "";
        if (/\d/.test(text)) {
            e.preventDefault();
            fillCode($(this).closest(".wpsp-form"), text);
        }
    });

    /* ---- Edit phone / resend ---- */
    $(document).on("click", ".wpsp-form .rs-edit-phone", function () {
        var $form = $(this).closest(".wpsp-form");
        $form.find(".rs-step-code").attr("hidden", true);
        $form.find(".rs-step-phone").removeAttr("hidden");
        setMessage($form, "");
        $form.find(".rs-phone").focus();
    });

    $(document).on("click", ".wpsp-form .rs-resend:not([disabled])", function () {
        $(this).closest(".wpsp-form").find(".rs-send").trigger("click");
    });

    function startTimer($form, seconds) {
        var $resend = $form.find(".rs-resend").prop("disabled", true);
        var $timer = $form.find(".rs-timer");
        var remaining = seconds;
        clearInterval($form.data("rsTimer"));
        $timer.text(" (" + remaining + ")");
        var id = setInterval(function () {
            remaining--;
            if (remaining <= 0) {
                clearInterval(id);
                $resend.prop("disabled", false);
                $timer.text("");
            } else {
                $timer.text(" (" + remaining + ")");
            }
        }, 1000);
        $form.data("rsTimer", id);
    }
})(jQuery);
