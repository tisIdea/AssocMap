<footer class="public-footer">
    <div class="public-container public-footer-content">
        <div class="public-footer-identity">
            <a class="public-brand" href="{{ route('home') }}#home"><span class="public-brand-mark"><x-assocmap-mark /></span><strong>Assoc<span>Map</span></strong></a>
            <p>A connected perspective on community<br>livelihood information and program monitoring.</p>
        </div>
        <nav aria-label="Footer navigation"><span class="public-footer-label">Explore</span><a href="{{ route('home') }}#about">About AssocMap</a><a href="{{ route('home') }}#features">System features</a><a href="{{ route('public.gis') }}">Public GIS Map</a><a href="{{ route('login') }}">Account login</a><a href="{{ route('public.privacy') }}">Privacy Notice</a><a href="{{ route('public.terms') }}">Terms of Use</a></nav>
        <nav aria-label="Official resources"><span class="public-footer-label">Official resources</span><a href="https://r7.bfar.da.gov.ph/" target="_blank" rel="noopener noreferrer">BFAR Region VII <span class="sr-only">(opens in a new tab)</span><x-icon name="arrow" /></a><a href="https://saad.da.gov.ph/" target="_blank" rel="noopener noreferrer">SAAD Program <span class="sr-only">(opens in a new tab)</span><x-icon name="arrow" /></a><a href="{{ route('home') }}#contact">Program inquiries</a></nav>
    </div>
    <div class="public-container public-footer-bottom"><p>Independent academic Capstone project.<br>Not an official BFAR or SAAD website. No agency endorsement is implied.</p><a href="{{ route('home') }}#home">Back to top <x-icon name="arrow" /></a></div>
</footer>
