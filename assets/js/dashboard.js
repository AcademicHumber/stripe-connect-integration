jQuery(function ($) {
  $(".wpcf-stripe-connect-deauth").click(function () {
    let element = $(this);

    $.ajax({
      type: "POST",
      url: wpcf_ajax_object.ajax_url,
      data: { action: "wpcf_stripe_disconnect" },
      success: function (data) {
        wpcf_modal(data);
      },
      error: function (jqXHR, textStatus, errorThrown) {
        wpcf_modal({ success: 0, message: "Error" });
      },
    });
  });
});
