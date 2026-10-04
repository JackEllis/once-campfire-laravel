@extends('layouts.app', ['title' => 'Chat bots'])
@section('nav')<a class="btn" href="/account/edit">Back to account settings</a>@endsection
@section('content')
<section class="panel panel--wide txt-align-center flex flex-column position-relative"><div class="pad-inline-double center"><h1 class="margin-none">Chat bots</h1><p>With Chat bots, other sites and services can post updates directly to Campfire.</p><a href="/account/bots/new" class="btn btn--reversed txt-large" aria-label="Add a chat bot">Add a chat bot</a></div>
<menu class="flex flex-column gap margin-none pad">
@foreach($bots as $bot)
<li class="flex flex-column gap flush fill-shade border-radius pad-block pad-inline-double"><div class="flex align-center gap"><figure class="avatar flex-item-no-shrink"><img src="/users/{{ $bot->avatarToken() }}/avatar" width="48" height="48" alt=""></figure><strong class="txt-large">{{ $bot->name }}</strong><a href="/account/bots/{{ $bot->id }}/edit" class="btn flex-item-justify-end">Edit {{ $bot->name }}</a></div>
@foreach($bot->rooms()->where('type','!=','Rooms::Direct')->orderBy('name')->get() as $room)
<fieldset class="gap max-width pad border border-radius"><legend><strong>{{ $room->name }}</strong></legend>
@foreach(["curl -d 'Hello!' ".url('/rooms/'.$room->id.'/'.$bot->id.'-'.$bot->bot_token.'/messages') => 'curl command for posting messages', 'curl -F "attachment=@/path/to/file" '.url('/rooms/'.$room->id.'/'.$bot->id.'-'.$bot->bot_token.'/messages') => 'curl command for posting attachments'] as $command=>$label)
<div class="flex align-center gap"><input type="text" class="input full-width fill-white" readonly aria-label="{{ $label }}" value="{{ $command }}"><button class="btn" data-controller="copy-to-clipboard" data-action="copy-to-clipboard#copy" data-copy-to-clipboard-content-value="{{ $command }}" data-copy-to-clipboard-success-class="btn--success">Copy</button></div>
@endforeach
</fieldset>
@endforeach
</li>
@endforeach
</menu></section>
@endsection
