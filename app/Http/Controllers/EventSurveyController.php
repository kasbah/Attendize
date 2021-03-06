<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEventQuestionRequest;
use App\Models\Event;
use App\Models\Attendee;
use App\Models\Question;
use App\Models\QuestionAnswer;
use App\Models\QuestionType;
use JavaScript;
use Illuminate\Http\Request;
use Excel;

/*
  Attendize.com   - Event Management & Ticketing
 */

class EventSurveyController extends MyBaseController
{
    /**
     * Show the event survey page
     *
     * @param Request $request
     * @param $event_id
     * @return mixed
     */
    public function showEventSurveys(Request $request, $event_id)
    {
        $event = Event::scope()->findOrFail($event_id);

        JavaScript::put([
            'postUpdateQuestionsOrderRoute' => route('postUpdateQuestionsOrder', ['event_id' => $event_id]),
        ]);

        $data = [
            'event'      => $event,
            'questions'  => $event->questions->sortBy('sort_order'),
            'sort_order' => 'asc',
            'sort_by'    => 'title',
            'q'          => '',
        ];

        return view('ManageEvent.Surveys', $data);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function showCreateEventQuestion(Request $request, $event_id)
    {
        $event = Event::scope()->findOrFail($event_id);

        return view('ManageEvent.Modals.CreateQuestion', [
            'event'          => $event,
            'question_types' => QuestionType::all(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @access public
     * @param  StoreEventQuestionRequest $request
     * @return \Illuminate\Http\Response
     */
    public function postCreateEventQuestion(StoreEventQuestionRequest $request, $event_id)
    {
        // Get the event or display a 'not found' warning.
        $event = Event::findOrFail($event_id);

        // Create question.
        $question = Question::createNew(false, false, true);
        $question->title = $request->get('title');
        $question->is_required = ($request->get('is_required') == 'yes');
        $question->question_type_id = $request->get('question_type_id');
        $question->save();

        // Get options.
        $options = $request->get('option');

        // Add options.
        if ($options && is_array($options)) {
            foreach ($options as $option_name) {
                if (trim($option_name) !== '') {
                    $question->options()->create([
                        'name' => $option_name,
                    ]);
                }
            }
        }

        // Get tickets.
        $ticket_ids = $request->get('tickets');

        $question->tickets()->attach($ticket_ids);

        $event->questions()->attach($question->id);

        session()->flash('message', 'Successfully Created Question');

        return response()->json([
            'status'      => 'success',
            'message'     => 'Refreshing..',
            'redirectUrl' => '',
        ]);
    }


    /**
     * Show the Edit Question Modal
     *
     * @param Request $request
     * @param $event_id
     * @param $question_id
     * @return mixed
     */
    public function showEditEventQuestion(Request $request, $event_id, $question_id)
    {
        $question = Question::scope()->findOrFail($question_id);
        $event = Event::scope()->findOrFail($event_id);

        $data = [
            'question'       => $question,
            'event'          => $event,
            'question_types' => QuestionType::all(),
        ];

        return view('ManageEvent.Modals.EditQuestion', $data);
    }


    /**
     * Edits a question
     *
     * @param Request $request
     * @param $event_id
     * @param $question_id
     * @return mixed
     */
    public function postEditEventQuestion(Request $request, $event_id, $question_id)
    {
        // Get the event or display a 'not found' warning.
        $event = Event::scope()->findOrFail($event_id);

        // Create question.
        $question = Question::scope()->findOrFail($question_id);
        $question->title = $request->get('title');
        $question->is_required = $request->get('is_required');
        $question->question_type_id = $request->get('question_type_id');
        $question->save();

        $question_type = QuestionType::find($question->question_type_id);

        if ($question_type->has_options) {

            // Get options.
            $options = $request->get('option');

            $question->options()->delete();

            // Add options.
            if ($options && is_array($options)) {
                foreach ($options as $option_name) {
                    if (trim($option_name) !== '') {
                        $question->options()->create([
                            'name' => $option_name,
                        ]);
                    }
                }
            }
        }


        // Get tickets.
        $ticket_ids = (array) $request->get('tickets');

        $question->tickets()->sync($ticket_ids);

        session()->flash('message', 'Successfully Edited Question');

        return response()->json([
            'status'      => 'success',
            'message'     => 'Refreshing..',
            'redirectUrl' => '',
        ]);

    }

    /**
     * Delete a question
     *
     * @param Request $request
     * @param $event_id
     * @return mixed
     */
    public function postDeleteEventQuestion(Request $request, $event_id)
    {
        $question_id = $request->get('question_id');

        $question = Question::scope()->find($question_id);

        $question->answers()->delete();

        if ($question->delete()) {

            session()->flash('message', 'Question Successfully Deleted');

            return response()->json([
                'status'      => 'success',
                'message'     => 'Refreshing..',
                'redirectUrl' => '',
            ]);
        }

        return response()->json([
            'status'  => 'error',
            'id'      => $question->id,
            'message' => 'This question can\'t be deleted.',
        ]);
    }

    /**
     * Show all attendees answers to questions
     *
     * @param Request $request
     * @param $event_id
     * @param $question_id
     * @return mixed
     */
    public function showEventQuestionAnswers(Request $request, $event_id, $question_id)
    {
        $answers = QuestionAnswer::scope()->where('question_id', $question_id)->get();
        $question = Question::scope()->withTrashed()->find($question_id);

        $attendees = Attendee::scope()
            ->has('answers')
            ->where('event_id', $event_id)
            ->get();

        $data = [
            'answers'  => $answers,
            'question' => $question,
        ];

        return view('ManageEvent.Modals.ViewAnswers', $data);
    }


    /**
     * Export answers
     *
     * @todo This doesn't work :-|
     * @param Request $request
     * @param $event_id
     * @param string $export_as
     */
    public function showExportAnswers(Request $request, $event_id, $export_as = 'xlsx')
    {

        Excel::create('answers-as-of-' . date('d-m-Y-g.i.a'), function ($excel) use ($event_id) {

            $excel->setTitle('Survey Answers');

            // Chain the setters
            $excel->setCreator(config('attendize.app_name'))
                ->setCompany(config('attendize.app_name'));

            $excel->sheet('survey_answers_sheet_!', function ($sheet) use ($event_id) {

                $event = Event::scope()->findOrFail($event_id);

                $rows[] = array_merge([
                    'Order Ref',
                    'Attendee Name',
                    'Attendee Email',
                    'Attendee Ticket'
                ], $event->questions->lists('title')->toArray());

                $attendees = $event->attendees()->has('answers')->get();
                foreach ($attendees as $attendee) {

                    $rows[] = array_merge([
                        $attendee->order->order_reference,
                        $attendee->full_name,
                        $attendee->email,
                        $attendee->ticket->title
                    ], $attendee->answers->lists('answer_text')->toArray());

                }

                $sheet->fromArray($rows);

                // Set gray background on first row
                $sheet->row(1, function ($row) {
                    $row->setBackground('#f5f5f5');
                });
            });
        })->export($export_as);


    }

    public function postEnableQuestion(Request $request, $event_id, $question_id)
    {

        $question = Question::scope()->find($question_id);

        $question->is_enabled = ($question->is_enabled == 1) ? 0 : 1;

        if ($question->save()) {
            return response()->json([
                'status'  => 'success',
                'message' => 'Question Successfully Updated',
                'id'      => $question->id,
            ]);
        }

        return response()->json([
            'status'  => 'error',
            'id'      => $question->id,
            'message' => 'Whoops! Looks like something went wrong. Please try again.',
        ]);
    }


    /**
     * Updates the sort order of event questions
     *
     * @param Request $request
     * @return mixed
     */
    public function postUpdateQuestionsOrder(Request $request)
    {
        $question_ids = $request->get('question_ids');
        $sort = 1;

        foreach ($question_ids as $question_id) {
            $question = Question::scope()->find($question_id);
            $question->sort_order = $sort;
            $question->save();
            $sort++;
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Question Order Successfully Updated',
        ]);
    }
}
