<div data-feedback-root>
    @if($payload['notification'])
        <section class="notice notice-{{ $payload['notification']['type'] === 'success' ? 'success' : 'error' }}" data-feedback-fallback role="{{ $payload['notification']['type'] === 'success' ? 'status' : 'alert' }}">
            <strong>{{ $payload['notification']['title'] }}</strong><p>{{ $payload['notification']['message'] }}</p>
        </section>
    @endif
    <script type="application/json" data-feedback-payload>@json($payload)</script>
    <dialog id="global-feedback-modal" class="feedback-modal" data-global-feedback role="alertdialog" aria-modal="true" aria-labelledby="global-feedback-title" aria-describedby="global-feedback-message">
        <div class="feedback-content">
            <span class="feedback-icon" aria-hidden="true"><span data-feedback-success><x-icon name="check" /></span><span data-feedback-error hidden><x-icon name="info" /></span></span>
            <h2 id="global-feedback-title" data-feedback-title></h2>
            <p id="global-feedback-message" data-feedback-message></p>
            <div class="feedback-actions"><button type="button" class="button button-primary" data-feedback-acknowledge autofocus>OK</button></div>
        </div>
    </dialog>
</div>
