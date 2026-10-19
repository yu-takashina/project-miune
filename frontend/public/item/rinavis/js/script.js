//ドロップダウンメニュー
$(function() {
  $('header nav ul li').hover(function() {
    $("ul:not(:animated)", this).slideDown();
  }, function() {
    $("ul.child", this).slideUp();
  });
  $('.guideSideNav')
    //ボタンを-200で配置
    .each(function() {
      $(this).css('margin-right', '-170px')
    })
    .hover(
      function() {
        $(this).stop().animate({
          'marginRight': '0px'
        }, 'fast');
      },
      function() {
        $(this).stop().animate({
          'marginRight': '-170px'
        }, 'slow');
      }
    );
    $('a[href^="https://www.rinavis-leather.com/"]').on('click', function () {
      if (window.confirm('【必ずご確認ください】\n『皮革製品・ブランド品クリーニング・メンテナンス部門』専門サイトへ移動します。ご利用には別途会員登録が必要となります事ご了承ください。※なおサイト間のポイント移行は可能です。詳しくはリナビス事務局までお問合せくださいませ。')) {
        location.href = $(this).attr('href');
        return true;
      }
      return false;
    });

    /* ------------------------
     * 191011 TOP Renew 
     * ------------------------ */

    /* アンカーリンクのスムーススクロール */
    $('a[href^="#"]').click(function(){
      var speed = 500;
      var href= $(this).attr("href");
      var target = $(href == "#" || href == "" ? 'html' : href);
      var position = target.offset().top;
      $("html, body").animate({scrollTop:position}, speed, "swing");
      return false;
    });

    /* サイドナビのカレンダー部分 日付取得処理 */
    var now = new Date();
    var m = now.getMonth() + 1;
    var d = now.getDate();
    var day = now.getDay();
    var day_list = ['日', '月', '火', '水', '木', '金', '土'][day];
    var monthIco = "/item/rinavis/img/common/ico_month" + m + ".png";
    var dateText = "<span>" + m + "</span>月<span>" + d + "</span>日<br>" + day_list + "曜日"
    /*$('#js-date').html(dateText);
    $('#js-monthIco').attr('src', monthIco);*/

    /* カレンダーレンダリング修正 */
    /*if(document.URL.match("pc_top_renew")) {*/
      console.log("191015 debug");
      var html = '';

      var monthPath = '<img id="js-monthIco" src="/item/rinavis/img/common/ico_month' + m + '.png" alt="">';
      var datePath = '<p id="js-date"><span>' + m + '</span>月<span>' + d + '</span>日<br>' + day_list + '曜日</p>';

      html += '<div class="rn-side_date">';
      html += '<div class="rn-side_ico">';
      html += monthPath;
      html += '</div>';
      html += '<div class="rn-side_date_txt">';
      html += datePath;
      html += '</div>';
      html += '</div>';

      console.log(html);
      if( m > 0 && d > 0 ){
        $('.rn-side_head').append(html);
      } else {
        console.log("日付が取得できませんでした。");
      }
    /*}*/

    /* FOUC対応 */
});