<?php

namespace App\Http\Requests;

use App\Support\ApplicationInspection\InspectionUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Input for starting an inspection (`jobs.applications.store`). Everything is optional: a plain POST inspects the job from
 * wherever it was found. Two explicit actions on the Application URL ride on the same request, so entering a URL and
 * inspecting it is one atomic step rather than two that could race:
 *
 * - `application_url`: the person's own application URL, saved as an override and inspected. It must pass the same URL
 *   policy as any other (http(s), no credentials, no internal hosts); it is never trusted for being typed by a person.
 * - `clear_application_url`: drop any saved application URL (a person's or a resolved one) and re-resolve from the job's
 *   original listing.
 *
 * Local-first, single-user app with no auth, so every request is authorized, like the other job endpoints.
 */
class StartInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'application_url' => ['nullable', 'string', 'max:2048'],
            'clear_application_url' => ['nullable', 'boolean'],
            // The one-click open: "start an inspection only if this job has never been inspected". A list or tab that is
            // out of date can then never start a second one over a result that already exists.
            'only_if_none' => ['nullable', 'boolean'],
            'return_to' => ['nullable', 'string'],
            'resume' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $url = $this->input('application_url');

                if (is_string($url) && trim($url) !== '' && $this->clearsApplicationUrl()) {
                    $validator->errors()->add('application_url', 'Either enter an application URL or clear the saved one, not both.');
                }

                if (is_string($url) && trim($url) !== '' && InspectionUrl::acceptable($url) === null) {
                    $validator->errors()->add('application_url', 'Enter a full web address (https://…) for the application form. Addresses on a private network are not allowed.');
                }
            },
        ];
    }

    /** The cleaned application URL the person supplied, or null when none was. */
    public function applicationUrl(): ?string
    {
        $url = $this->input('application_url');

        return is_string($url) && trim($url) !== '' ? InspectionUrl::acceptable($url) : null;
    }

    public function onlyIfNone(): bool
    {
        return $this->boolean('only_if_none');
    }

    public function clearsApplicationUrl(): bool
    {
        return $this->boolean('clear_application_url');
    }
}
