<?php

namespace App\Traits;

use App\Http\Resources\TicketResource;
use App\Models\HiddenChatMessage;
use App\Models\Manager;
use App\Models\Ticket;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

trait TicketTrait
{
  public static function GetReason($message)
  {
    $client = new \GuzzleHttp\Client();
    $res = $client->post(env('NLP_URL'), ['form_params' => ['message' => $message]])->getBody()->getContents();
    $name = json_decode($res, true);
    $reason = \App\Models\Reason::whereName($name)->first();
    return $reason;
  }

  public static function GetManagersForReason($reason_id)
  {
    return \App\Models\Reason::join('groups', 'groups.id', 'reasons.group_id')
      ->join('manager_groups', 'manager_groups.group_id', 'groups.id')
      ->join('managers', 'managers.id', 'manager_groups.manager_id')
      ->join('users', 'users.crm_id', 'managers.crm_id')
      ->where('reasons.id', $reason_id)
      ->select('managers.crm_id', 'users.name', 'reasons.weight', 'reasons.name AS reason')
      ->groupBy('reasons.id', 'groups.id', 'manager_groups.id', 'managers.id', 'users.id')
      ->get();
  }

  public static function SelectResponsiveId($managers): int
  {
    $m = array_map(fn($e) => $e['crm_id'], $managers->toArray());
    $data = \Illuminate\Support\Facades\DB::table('tickets')
      ->join('managers', 'managers.crm_id', 'tickets.manager_id')
      ->leftJoin(
        'messages',
        fn($q) => $q
          ->on('messages.ticket_id', 'tickets.id')
          ->whereRaw('messages.id IN (SELECT MAX(m2.id) FROM messages as m2 join tickets as t2 on t2.id = m2.ticket_id GROUP BY t2.id)')
      )
      ->where('tickets.active', true)
      ->whereIn('tickets.manager_id', $m)
      ->whereNotIn('messages.user_crm_id', $m)
      ->select(
        'tickets.id',
        'tickets.weight',
        'tickets.manager_id',
        'messages.id',
        'messages.user_crm_id AS last_message_user_id'
      )
      ->get()->toArray();

    if (count($data) == 0) {
      return 0;
    } elseif (count($data) < count($m)) {
      foreach ($data as $ticket) {
        $key = array_search($ticket->manager_id, $m);
        if ($key === false) {
          return 0;
        } else {
          unset($m[$key]);
        }
      }

      return intval(array_values($m)[0]);
    }

    $sums = array();
    foreach ($data as $value) {
      if (array_key_exists($value->manager_id, $sums)) {
        $sums[$value->manager_id] += $value->weight;
      } else {
        $sums[$value->manager_id] = $value->weight;
      }
    }

    $responsive_ids = array_keys($sums, min($sums));

    $responsive_id = count($responsive_ids) > 0 ? $responsive_ids[0] : $responsive_ids;

    return $responsive_id;
  }

  public static function SaveAttachment($message_id, $content)
  {
    $attachments_path = 'public/attachments/' . $message_id;
    if (!Storage::disk('local')->exists($attachments_path)) {
      Storage::makeDirectory($attachments_path);
    }

    $path = $attachments_path . '/' . $content['name'];
    $file = file_get_contents($content["tmp_name"]);

    Storage::disk('local')->put($path, $file);

    $new_data = [
      'message_id' => $message_id,
      'name' => $content['name'],
      'link' => Storage::url($path),
    ];
    $attachment = \App\Models\Attachment::create($new_data);

    return \App\Http\Resources\AttachmentResource::make($attachment);
  }

  public static function MarkedTicketsPreparing()
  {
    $tickets = Ticket::whereActive(false)
      ->whereRaw('updated_at < (utc_timestamp() - INTERVAL 1 DAY)')
      ->pluck('id');

    foreach ($tickets as $id) {
      $result = static::FinishTicket($id);
      Log::info($result['message']);
    }
  }

  public static function FinishTicket($old_ticket_id, $mark = 0)
  {
    $ticket = Ticket::findOrFail($old_ticket_id);
    $data = clone ($ticket);
    $data->old_ticket_id = $data->id;
    $data->mark = $mark;

    $validated = \Illuminate\Support\Facades\Validator::make($data->toArray(), [
      'old_ticket_id' => 'required|integer|min:1',
      'user_id' => 'required|integer|min:1',
      'manager_id' => 'required|integer|min:1',
      'reason_id' => 'required|integer|min:1',
      'weight' => 'required|integer|min:1',
      'mark' => 'required|integer|min:0|max:3',
    ])->validate();

    $resolved = \App\Models\ResolvedTicket::firstOrNew($validated);
    if ($resolved->exists) {
      return [
        'status' => false,
        'data' => null,
        'message' => 'Попытка завершить уже завершённый тикет'
      ];
    }

    HiddenChatMessage::create([
      'content' => 'Тикет завершён',
      'user_crm_id' => 0,
      'ticket_id' => $ticket->id,
    ]);

    $resolved->save();
    $result = $ticket->delete();
    $message = "Тикет №{$ticket->id} успешно завершён";

    self::SendMessageToWebsocket("{$ticket->manager_id}.ticket.delete", [
      'id' => $ticket->id,
      'message' => $message,
    ]);
    self::SendMessageToWebsocket("{$ticket->user_id}.ticket.delete", [
      'id' => $ticket->id,
      'message' => $message,
    ]);
    $part_ids = \App\Models\Participant::whereTicketId($ticket->id)
      ->pluck('user_crm_id')->toArray();
    foreach ($part_ids as $id) {
      self::SendMessageToWebsocket("{$id}.ticket.delete", [
        'id' => $ticket->id,
        'message' => $message,
      ]);
    }

    return [
      'status' => true,
      'data' => $result,
      'message' => $message
    ];
  }

  public static function TryToRedistributeByReason($reason_id, $old_crm_id, $new_crm_ids, $count)
  {
    $tickets = Ticket::whereManagerId($old_crm_id)
      ->whereReasonId($reason_id)->orderByDesc('id')->take($count)->get();

    $managers = Manager::join('users', 'users.crm_id', 'managers.crm_id')
      ->whereRoleId(2)->whereIn('managers.crm_id', $new_crm_ids)->get();

    $tickets_ids = array_map(fn($e) => $e['id'], $tickets->toArray());
    $participants = \App\Models\Participant::whereIn('ticket_id', $tickets_ids)
      ->whereIn('user_crm_id', $new_crm_ids)->get();

    $managersCount = count($new_crm_ids);
    $currentKey = 0;
    $data = [];
    $hiddenChatMessages = [];
    $participants_ids = [];

    foreach ($tickets as $key => $ticket) {
      if ($key == $count)
        break;
      if ($currentKey == $managersCount)
        $currentKey = 0;

      $manager_id = $new_crm_ids[$currentKey++];
      array_push($data, [
        'ticket_id' => $ticket->id,
        'user_crm_id' => $manager_id,
      ]);

      array_push($hiddenChatMessages, [
        'content' => "Новый ответственный: {$managers->where('crm_id', $manager_id)->first()->name}",
        'user_crm_id' => 0,
        'ticket_id' => $ticket->id,
      ]);

      $participant = $participants->where('ticket_id', $ticket->id)
        ->where('user_crm_id', $manager_id)->first();
      if (isset($participant))
        array_push($participants_ids, $participant->id);
    }

    dd($data, $managers, $hiddenChatMessages);
    //TODO распределить тикеты по участникам группы



    \App\Models\Participant::whereIn('id', $participants_ids)->delete();
    //TODO убрать из участников новых ответственных


    HiddenChatMessage::create($hiddenChatMessages);
    //TODO записать в системный чат, что сменился ответственный


    self::SendMessageToWebsocket("{$ticket->manager_id}.ticket", [
      'ticket' => TicketResource::make($ticket),
    ]);
    //TODO новым ответственным прислать в уведомлении, что они стали ответственными за число тикетов
    //TODO Через сокет каждому добавить все новые тикеты 

    // $ticket = Ticket::findOrFail($old_ticket_id);

    // self::SendMessageToWebsocket("{$ticket->manager_id}.ticket.delete", [
    //   'id' => $ticket->id,
    //   'message' => $message,
    // ]);
    // self::SendMessageToWebsocket("{$ticket->user_id}.ticket.delete", [
    //   'id' => $ticket->id,
    //   'message' => $message,
    // ]);
    // $part_ids = \App\Models\Participant::whereTicketId($ticket->id)
    //   ->pluck('user_crm_id')->toArray();
    // foreach ($part_ids as $id) {
    //   self::SendMessageToWebsocket("{$id}.ticket.delete", [
    //     'id' => $ticket->id,
    //     'message' => $message,
    //   ]);
    // }

    // return [
    //   'status' => true,
    //   'data' => $result,
    //   'message' => $message
    // ];
  }

  public static function SendMessageToWebsocket($recipient_id, $data)
  {
    // $channel_id = '#support.' . md5($data->user_id) . md5(env('CENTRIFUGE_SALT'));
    $channel_id = '#support.' . $recipient_id;
    $client = new \phpcent\Client(
      env('CENTRIFUGE_URL') . '/api',
      '8ffaffac-8c9e-4a9c-88ce-54658097096e',
      'ee93146a-0607-4ea3-aa4a-02c59980647e'
    );
    $client->setSafety(false);
    $client->publish($channel_id, $data);
  }

  public static function SendNotification($recipient_id, $message, $ticket_id)
  {
    $market_id = env('MARKETPLACE_ID');
    $content = "New ТП:\r\n{$message}\r\n[URL=/marketplace/app/{$market_id}/?id={$ticket_id}]Перейти[/URL]";
    $WEB_HOOK_URL = "https://xn--24-9kc.xn--d1ao9c.xn--p1ai/rest/10033/t8swdg5q7trw0vst/im.message.add.json?USER_ID={$recipient_id}&MESSAGE={$content}&URL_PREVIEW=Y";

    \Illuminate\Support\Facades\Http::get($WEB_HOOK_URL);
  }
}