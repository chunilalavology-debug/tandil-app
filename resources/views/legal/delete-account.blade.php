@extends('layouts.legal')

@section('title', 'Delete Account')

@section('content')
    <article class="legal-prose space-y-6">
        <header>
            <h1>Account Deletion Request</h1>
            <p class="text-slate-600 mt-2">
                Request deletion of your TANDIL account and associated personal data. This page is public and does not require signing in.
            </p>
        </header>

        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900" role="status">
                <p class="font-semibold">Request received</p>
                <p class="mt-1">{{ session('success') }}</p>
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800" role="alert">
                <p class="font-semibold">Please fix the following:</p>
                <ul class="mt-2 list-disc pl-5 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="rounded-xl border border-slate-200 bg-white p-6 space-y-4">
            <h2>How to request deletion</h2>
            <ol class="list-decimal pl-5 space-y-2 text-slate-700">
                <li>Complete the form below with the email or phone number registered to your TANDIL account.</li>
                <li>Confirm that you understand deletion is permanent.</li>
                <li>Submit the request. You will receive a reference number (for example ADR-XXXXXXXX).</li>
                <li>TANDIL support verifies your identity, then deletes eligible personal data.</li>
                <li>Requests are normally completed within <strong>30 days</strong> of verification.</li>
            </ol>
        </div>

        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-6 space-y-3">
            <h2 class="!mt-0">Delete from the Android app</h2>
            <p class="text-slate-700">
                Account deletion is also available inside the TANDIL Android app without using this web form:
            </p>
            <ol class="list-decimal pl-5 space-y-2 text-slate-700">
                <li>Open the TANDIL app and sign in.</li>
                <li>Go to <strong>Profile</strong> (or <strong>Settings</strong>).</li>
                <li>Tap <strong>Delete Account</strong>.</li>
                <li>Confirm the warning dialog. Your account and eligible personal data are deleted immediately for client accounts.</li>
            </ol>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-6 space-y-4">
            <h2>Data that will be deleted</h2>
            <ul class="list-disc pl-5 space-y-2 text-slate-700">
                <li>Account profile (name, email, phone, password hashes, profile photo)</li>
                <li>Saved addresses and payment methods stored in TANDIL</li>
                <li>Cart, checkout sessions, wallet credits, loyalty balances linked to the account</li>
                <li>Push notification tokens and in-app notifications</li>
                <li>Chat messages and support tickets that are not required for legal retention</li>
                <li>Authentication sessions and API tokens</li>
            </ul>
            <p class="text-slate-700">
                After deletion you will no longer be able to sign in, and the account cannot be restored.
            </p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-6 space-y-4">
            <h2>Data retained for legal or accounting purposes</h2>
            <p class="text-slate-700">
                Some records cannot be fully erased immediately because UAE commercial, tax, and dispute obligations require retention.
                Where possible, personal identifiers are removed or anonymized while the business record remains.
            </p>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm text-slate-700 border border-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-3 py-2 border-b border-slate-200 font-semibold">Data category</th>
                            <th class="px-3 py-2 border-b border-slate-200 font-semibold">Why retained</th>
                            <th class="px-3 py-2 border-b border-slate-200 font-semibold">Retention period</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="px-3 py-2 border-b border-slate-100 align-top">Order, invoice, and payment transaction records (anonymized where feasible)</td>
                            <td class="px-3 py-2 border-b border-slate-100 align-top">Accounting, VAT, and audit requirements</td>
                            <td class="px-3 py-2 border-b border-slate-100 align-top"><strong>5 years</strong> from the end of the relevant tax period</td>
                        </tr>
                        <tr>
                            <td class="px-3 py-2 border-b border-slate-100 align-top">Service history / completed visit reports needed for warranty or disputes</td>
                            <td class="px-3 py-2 border-b border-slate-100 align-top">Contract performance and dispute resolution</td>
                            <td class="px-3 py-2 border-b border-slate-100 align-top"><strong>Up to 5 years</strong>, then deleted or anonymized</td>
                        </tr>
                        <tr>
                            <td class="px-3 py-2 border-b border-slate-100 align-top">Fraud-prevention and security logs</td>
                            <td class="px-3 py-2 border-b border-slate-100 align-top">Protect users and the platform</td>
                            <td class="px-3 py-2 border-b border-slate-100 align-top"><strong>Up to 2 years</strong></td>
                        </tr>
                        <tr>
                            <td class="px-3 py-2 align-top">Records required by a lawful authority request or ongoing legal claim</td>
                            <td class="px-3 py-2 align-top">Legal compliance</td>
                            <td class="px-3 py-2 align-top">Until the obligation or claim ends, then deleted or anonymized</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-slate-700">
                Full payment card numbers are not stored by TANDIL; card processing is handled by certified payment providers under their own policies.
            </p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-6 space-y-4">
            <h2>Secure processing</h2>
            <ul class="list-disc pl-5 space-y-2 text-slate-700">
                <li>This web form does <strong>not</strong> delete an account instantly. Support verifies identity first.</li>
                <li>Requests are rate-limited, protected with CSRF tokens, and logged with a unique reference.</li>
                <li>Only authorized TANDIL staff can complete deletion after verification.</li>
                <li>In-app deletion for signed-in client accounts uses authenticated API calls and removes eligible personal data immediately.</li>
            </ul>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-6">
            <h2 class="!mt-0 mb-4">Submit a deletion request</h2>

            <form method="POST" action="{{ route('legal.delete-account.store') }}" class="space-y-4" novalidate>
                @csrf

                {{-- Honeypot for bots; must stay empty --}}
                <div class="hidden" aria-hidden="true">
                    <label for="website">Website</label>
                    <input type="text" name="website" id="website" tabindex="-1" autocomplete="off" value="">
                </div>

                <div>
                    <label for="full_name" class="block text-sm font-medium text-slate-800">Full name</label>
                    <input
                        id="full_name"
                        name="full_name"
                        type="text"
                        required
                        maxlength="120"
                        value="{{ old('full_name') }}"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900 shadow-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-200"
                    >
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-slate-800">Account email</label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        required
                        maxlength="190"
                        value="{{ old('email') }}"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900 shadow-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-200"
                    >
                </div>

                <div>
                    <label for="phone" class="block text-sm font-medium text-slate-800">Account phone <span class="font-normal text-slate-500">(optional)</span></label>
                    <input
                        id="phone"
                        name="phone"
                        type="tel"
                        maxlength="40"
                        value="{{ old('phone') }}"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900 shadow-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-200"
                    >
                </div>

                <div>
                    <label for="account_type" class="block text-sm font-medium text-slate-800">Account type</label>
                    <select
                        id="account_type"
                        name="account_type"
                        required
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900 shadow-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-200"
                    >
                        @php($selectedType = old('account_type', 'client'))
                        <option value="client" @selected($selectedType === 'client')>Client (Customer)</option>
                        <option value="vendor" @selected($selectedType === 'vendor')>Vendor</option>
                        <option value="worker" @selected($selectedType === 'worker')>Worker (Field Technician)</option>
                        <option value="supervisor" @selected($selectedType === 'supervisor')>Supervisor</option>
                        <option value="area_manager" @selected($selectedType === 'area_manager')>Area Manager</option>
                        <option value="hr" @selected($selectedType === 'hr')>HR Manager</option>
                        <option value="admin" @selected($selectedType === 'admin')>Admin</option>
                        <option value="other" @selected($selectedType === 'other')>Other</option>
                    </select>
                </div>

                <div>
                    <label for="reason" class="block text-sm font-medium text-slate-800">Reason <span class="font-normal text-slate-500">(optional)</span></label>
                    <textarea
                        id="reason"
                        name="reason"
                        rows="4"
                        maxlength="2000"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900 shadow-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-200"
                    >{{ old('reason') }}</textarea>
                </div>

                <label class="flex items-start gap-3 text-slate-700">
                    <input
                        type="checkbox"
                        name="confirm_deletion"
                        value="1"
                        class="mt-1 h-4 w-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-500"
                        @checked(old('confirm_deletion'))
                        required
                    >
                    <span>I understand that deleting my TANDIL account is permanent and that some anonymized business records may be retained as described above.</span>
                </label>

                <button
                    type="submit"
                    class="inline-flex items-center justify-center rounded-lg bg-emerald-800 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-900 focus:outline-none focus:ring-2 focus:ring-emerald-300"
                >
                    Submit deletion request
                </button>
            </form>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-6 space-y-2">
            <h2 class="!mt-0">Need help?</h2>
            <p class="text-slate-700">
                Email <a href="mailto:support@tandil.ae" class="text-emerald-800 underline">support@tandil.ae</a>
                or visit our <a href="{{ route('legal.support') }}" class="text-emerald-800 underline">Support</a> page.
                Include your deletion reference number if you already submitted a request.
            </p>
        </div>
    </article>
@endsection
