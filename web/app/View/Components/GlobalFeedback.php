<?php

namespace App\View\Components;

use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\Component;

class GlobalFeedback extends Component
{
    public array $payload;

    public function __construct(?array $notification = null)
    {
        $errors = View::shared('errors');
        $bags = [];
        if ($errors instanceof ViewErrorBag) {
            foreach ($errors->getBags() as $name => $bag) {
                if ($bag->any()) $bags[$name] = $bag->messages();
            }
        }
        $session = request()->hasSession() ? request()->session() : null;
        $success = $session?->get('success');
        $error = $session?->get('error');
        $invitation = $session?->get('invitation_result');
        if ($notification === null) {
            if (is_string($error) && $error !== '') {
                $notification = ['type' => 'error', 'title' => 'Operation Not Completed', 'message' => $error];
            } elseif ($bags !== []) {
                $notification = ['type' => 'error', 'title' => 'Review Your Entries',
                    'message' => 'The request was not completed. Review the form messages and correct the highlighted fields.'];
            } elseif (is_array($invitation) && is_string($invitation['message'] ?? null)) {
                $accepted = ($invitation['code'] ?? null) === 'provider_accepted';
                $notification = ['type' => $accepted ? 'success' : 'error',
                    'title' => $accepted ? 'Invitation Request Accepted' : 'Invitation Not Completed',
                    'message' => $invitation['message']];
            } elseif (is_string($success) && $success !== '') {
                $notification = ['type' => 'success', 'title' => 'Operation Successful', 'message' => $success];
            }
        }
        if ($notification !== null) {
            $notification = ['id' => (string) Str::uuid(),
                'type' => ($notification['type'] ?? null) === 'success' ? 'success' : 'error',
                'title' => is_string($notification['title'] ?? null) ? $notification['title'] : 'Operation Result',
                'message' => is_string($notification['message'] ?? null) ? $notification['message'] : 'Review the operation before continuing.'];
        }
        $this->payload = ['notification' => $notification, 'errors' => $bags];
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('components.global-feedback');
    }
}
