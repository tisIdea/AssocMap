<section class="form-section" aria-label="Private application evidence">
    <div class="field">
        @if(filled($application->endorsement_letter))
            <label for="endorsement-{{ $application->id }}">Endorsement Letter</label>
            <textarea id="endorsement-{{ $application->id }}" rows="8" readonly>{{ $application->endorsement_letter }}</textarea>
        @else
            <h3>Endorsement Letter</h3>
            <p>Not recorded</p>
        @endif
    </div>
    <div class="field">
        <h3>Applicant Photo</h3>
        @if(filled($application->applicant_photo_path))
            <img src="{{ route($photoRoute, $application) }}" alt="Applicant photo" loading="lazy" decoding="async" width="256" height="256" style="max-width: 100%; height: auto;" onerror="this.hidden = true; this.nextElementSibling.hidden = false;">
            <p hidden role="status">Photo unavailable. Refresh the page to check your current access or retry.</p>
        @else
            <p>Not recorded</p>
        @endif
    </div>
</section>
