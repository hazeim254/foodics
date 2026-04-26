<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContactMessageRequest;
use App\Mail\ContactMessageSubmitted;
use App\Models\ContactMessage;
use Illuminate\Support\Facades\Mail;

class ContactMessageController extends Controller
{
    public function index()
    {
        return view('contact');
    }

    public function store(StoreContactMessageRequest $request)
    {
        $contactMessage = ContactMessage::create([
            ...$request->validated(),
            'user_id' => auth()->id(),
        ]);

        Mail::to(config('mail.contact_to'))->send(new ContactMessageSubmitted($contactMessage));

        return back()->with('status', 'Your message has been sent successfully.');
    }
}
