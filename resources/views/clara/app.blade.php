<!doctype html>
<html @php(language_attributes()) data-accent="{{ $preferences['accent'] }}" data-scale="{{ $preferences['scale'] }}" data-effects="{{ $preferences['effects'] }}">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Clara</title>
  {{-- Bez wp_head()/wp_footer(): panel nie ładuje skryptów i znaczników wtyczek frontu (GTM, debug itp.). --}}
  @if ($assets)
    {!! $assets !!}
  @endif
</head>

<body class="clara-app">
  <script>
    window.claraConfig = {!! wp_json_encode($config) !!};
  </script>

  <div id="clara-root">
    @unless ($assets)
      <main>
        <h1>Clara</h1>
        <p>Brak zbudowanych assetów panelu. Uruchom <code>yarn build</code> lub <code>yarn dev</code>.</p>
      </main>
    @endunless
  </div>

  {{-- wp-element (React z WordPressa) i wp-api-fetch z middleware nonce/root REST. --}}
  @php(wp_print_scripts($scripts))
</body>

</html>
