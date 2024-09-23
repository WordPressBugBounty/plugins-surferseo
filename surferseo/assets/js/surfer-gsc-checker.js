jQuery(function ($) {
  function check_gsc_result() {
    var data = {
      action: 'surfer_test_gsc_traffic_gatherer',
      debug: 1,
      _surfer_nonce: surfer_lang._surfer_nonce,
    }

    $('.surfer-test-gsc-connection-box__result').text('Loading...')

    $.ajax({
      url: surfer_lang.ajaxurl,
      type: 'POST',
      data: data,
      dataType: 'json',
      async: true,
      success: function (response) {
        if (typeof response === 'object' && response !== null) {
          let content = ''
          $.each(response, function (key, value) {
            content += value + '\r\n'
          })
          $('.surfer-test-gsc-connection-box__result').text(content)
        } else {
          $('.surfer-test-gsc-connection-box__result').text(response)
        }
      },
    })
  }

  $('.surfer-perform-gsc-connection-test').on('click', function (event) {
    event.preventDefault()
    check_gsc_result()
  })

  function transfer_data_to_new_format() {
    var data = {
      action: 'surfer_transfer_gsc_data_to_new_format',
      _surfer_nonce: surfer_lang._surfer_nonce,
    }

    $.ajax({
      url: surfer_lang.ajaxurl,
      type: 'POST',
      data: data,
      dataType: 'json',
      async: true,
      success: function (response) {
        $('.surfer-gsc-transfer-data-box__result').text(response)
      },
    })
  }

  $('.surfer-gsc-transfer-data-box__button').on('click', function (event) {
    event.preventDefault()
    transfer_data_to_new_format()
  })

  function reconnect_posts_with_drafts() {
    var data = {
      action: 'surfer_gather_posts_to_reconnect',
      _surfer_nonce: surfer_lang._surfer_nonce,
    }

    $.ajax({
      url: surfer_lang.ajaxurl,
      type: 'POST',
      data: data,
      dataType: 'json',
      async: true,
      success: function (response) {
        if (response?.posts?.length > 0) {
          const posts_found = response.posts.length
          const message =
            $('.surfer-reconnect-posts-with-drafts-box__result').text() +
            '\r\n' +
            'Found ' +
            posts_found +
            ' posts to reconnect' +
            '\r\n' +
            'Starting transfer...'
          $('.surfer-reconnect-posts-with-drafts-box__result').text(message)
          make_transfer(response.posts, 0, posts_found)
        } else if (response?.posts?.length == 0) {
          const message =
            $('.surfer-reconnect-posts-with-drafts-box__result').text() +
            '\r\n' +
            'No posts found to reconnect'
          $('.surfer-reconnect-posts-with-drafts-box__result').text(message)
        } else {
          const message =
            $('.surfer-reconnect-posts-with-drafts-box__result').text() +
            '\r\n' +
            response.message
          $('.surfer-reconnect-posts-with-drafts-box__result').text(message)
        }
      },
    })
  }

  const per_page = 10

  function make_transfer(posts, done, max) {
    const bunch = posts.slice(done, done + per_page)

    const data = {
      action: 'surfer_reconnect_posts_with_drafts',
      posts: bunch,
      _surfer_nonce: surfer_lang._surfer_nonce,
    }

    $.ajax({
      url: surfer_lang.ajaxurl,
      type: 'POST',
      data: data,
      dataType: 'json',
      async: true,
      success: function (response) {
        const message =
          $('.surfer-reconnect-posts-with-drafts-box__result').text() +
          '\r\n' +
          response
        $('.surfer-reconnect-posts-with-drafts-box__result').text(message)
      },
    })

    const message =
      $('.surfer-reconnect-posts-with-drafts-box__result').text() +
      '\r\n' +
      'Transferred ' +
      (done + per_page) +
      ' out of ' +
      max +
      ' posts'

    $('.surfer-reconnect-posts-with-drafts-box__result').text(message)

    if (done + per_page < max) {
      make_transfer(posts, done + per_page, max)
    }
  }

  $('.surfer-reconnect-posts-with-drafts-box__button').on(
    'click',
    function (event) {
      event.preventDefault()
      $('.surfer-reconnect-posts-with-drafts-box__result').text(
        'Gathering posts...' + '\r\n'
      )
      reconnect_posts_with_drafts()
    }
  )
})
