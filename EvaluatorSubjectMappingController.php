<?php

namespace App\Modules\Masters\Http\Controllers;


use App\Http\Controllers\Controller;
use App\Role;
use App\RoleUser;
use App\Subject;
use App\User;
use App\Segment;
use function array_merge;
use function array_push;
use Illuminate\Http\Request;
use function response;
use function view;
use DB;
use App\Imports\Evaluatormapping;
use Illuminate\Support\Facades\File;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Class EvaluatorSubjectMappingController
 *
 * @package App\Modules\Masters\Http\Controllers
 */
class EvaluatorSubjectMappingController extends Controller
{
    public function list()
    {
        $evaluators = Role::users_with_role(['Evaluator'], ['users.id','users.name'])->pluck('name','id')->all();

        //Get all Subjects
        $subjects = Subject::select(
            DB::raw("CONCAT(subject_code,'-',subject_name) AS subject_name"),'id')->get()->pluck('subject_name', 'id')->all();

        return view('masters::evaluatormapping.list',
                [
                        'evaluators' => ['NULL' => 'Select Evaluator'] + $evaluators,
                        'subjects'   => ['NULL' => 'Select Subject'] + $subjects,
                ]);
    }

    /**
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */ 
    public function selectEvaluator(Request $request)
    {
        if ($request->has(['evaluator', 'type'])) {
            $user              = User::find($request->evaluator);
            $selected_subjects = $selected_segments = [];
            if ($user) {
                $user_subjects     = $user->subjects()->get();
                // instead of pluck this method is used for exact result
                $selected_subjects=  $user_subjects->mapWithKeys(function ($item) {
                return [$item['id'] => $item['subject_code'].'-'.$item['subject_name']];
                });

                $id                = $user_subjects->pluck('id');
                $segments = [];
                foreach($user_subjects as $user_subject) {
                    if($user_subject->pivot->segments)
                        $segments = array_merge($segments, explode(",",$user_subject->pivot->segments));

                }
                if(count($segments)>0) {
                    $selected_segments = Segment::whereIn('id',$segments)->pluck('segment_name','id');
                }
            }
            $subjects = Subject::
            select(
            DB::raw("CONCAT(subject_code,'-',subject_name) AS subject_name"),'id')
            ->whereNotIn('id', $id)->get()->pluck('subject_name', 'id');

            return response([
                    'status' => true,
                    'data'   => ['subjects' => $subjects, 'selected_subjects' => $selected_subjects, 'selected_segments' => $selected_segments],
            ]);
        }

    }

    /**
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    public function selectSubject(Request $request)
    {
        if ($request->has(['subject', 'type'])) {
            $subject           = Subject::find($request->subject);
            $selected_users = [];
            if ($subject) {
                
                if($request->segments) {
                    $segs = implode(',', $request->segments);
                    $subject_users  = $subject->users()->where('segments','LIKE','%'.$segs.'%')->get();
                } else {
                    $subject_users  = $subject->users()->get();    
                }
                $selected_users = $subject_users->pluck('name', 'id');
                $id             = $subject_users->pluck('id');
            }
            $users = Role::users_with_role(['Evaluator'], ['users.id','users.name'])->whereNotIn('id', $id)->pluck('name','id')->all();

            return response([
                    'status' => true,
                    'data'   => ['users' => $users, 'selected_users' => $selected_users],
            ]);
        }

    }

    /**
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */

    public function getSubjectSegments(Request $request) {
        
        if ($request->subject) { 
            $segs           = []; $totalSegs = 0;
            $subject        = Subject::where('id', $request->subject)->first();
            if($subject) {
                $qp         = $subject->question_paper()->first();
                $totalSegs  = $qp->segments()->count();
                $segs       = $qp->segments()->pluck('segment_name','id')->all();
            }
            return response([
                    'status' => true,
                    'data'   => ['segments' => $segs, 'total_segs' => $totalSegs],
            ]);

        }

    }

    public function pullSegments(Request $request) {
        
        if ($request->has(['id', 'type', 'values'])) { 
            $segs       = []; $sub_segs = [];
            $subjects   = Subject::whereIn('id',$request->values)->get();
            if($subjects) {
                foreach($subjects as $subject) {
                    $qp = $subject->question_paper()->first();
                    $segs[$qp->id] = $qp->segments()->pluck('segment_name','id')->all();
                    $sub_segs[$subject->id] = $qp->segments()->pluck('id')->all();
                }
            }
            return response([
                    'status' => true,
                    'data'   => ['segments' => $segs, 'subject_segments' => $sub_segs],
            ]);

        }

    }

    public function pullAssignedSegments(Request $request) {
        
        if ($request->has(['id', 'type', 'values'])) { 
            $segs       = []; 
            $user            = User::find($request->id);
            $user_subjects   = $user->subjects()->whereIn('subject_id',$request->values)->get();
            
            if($user_subjects) {
                foreach($user_subjects as $subject) {
                    $segments = [];
                    if($subject->pivot->segments)
                        $segments = explode(",",$subject->pivot->segments);

                    $segs[$subject->question_id] = Segment::whereIn('id',$segments)->pluck('segment_name','id')->all();
                }
            }
            return response([
                    'status' => true,
                    'data'   => ['segments' => $segs],
            ]);

        }

    }


    /**
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    public function move(Request $request)
    {
        if ($request->has(['id', 'type', 'values'])) {
            if ($request->type === 'etos') {
                $user = User::find($request->id);
                if ($user) {                    
                    
                    if(isset($request->segments)) {
                        $sync_arr = [];
                        $subjects = Subject::whereIn('id',$request->values)->get();
                        foreach($subjects as $subject) {
                            $avail_segs  = $subject->question_paper->segments()->pluck('id')->all();
                            
                            if($request->segments=='all') {
                                $sel_segs = $avail_segs;
                            }
                            else {
                                $sel_segs   = array_intersect($request->segments, $avail_segs);
                            }
                            $sync_arr[$subject->id] = ['segments'=>implode(',', $sel_segs)];
                        }
                        $message = $user->subjects()->syncWithoutDetaching($sync_arr);
                    } else {
                        $message = $user->subjects()->syncWithoutDetaching($request->values);
                    }

                    return response(['status' => true, 'message' => $message]);
                }

            }
            if ($request->type === 'stoe') {
                $subject = Subject::find($request->id);
                if ($subject) {
                    
                    if(isset($request->segments)) {
                        $sync_arr       = [];
                        $avail_segs     = $subject->question_paper->segments()->pluck('id')->all();
                        $users          = User::whereIn('id',$request->values)->get();
                        foreach($users as $user) {
                            
                            $sel_segs   = $request->segments;

                            $sync_arr[$user->id] = ['segments'=>implode(',', $sel_segs)];
                        }
                        $message = $subject->users()->syncWithoutDetaching($sync_arr);
                    } else {
                        $message = $subject->users()->syncWithoutDetaching($request->values);
                    }

                    //$message = $subject->users()->syncWithoutDetaching($request->values);

                    return response(['status' => true, 'message' => $message]);
                }
            }
        }

        return response(['status' => false, 'message' => 'Something went wrong']);

    }


    /**
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    public function delete(Request $request)
    {
        if ($request->has(['id', 'type', 'values'])) {
            if ($request->type === 'etos') {
                $user = User::find($request->id);
                if ($user) {
                                                            
                    if(isset($request->segments) && count($request->segments)>0) {
                        
                        $sync_arr   = [];
                        $delete_arr = [];
                        $subjects = Subject::whereIn('id',$request->values)->get();
                        foreach($subjects as $subject) {
                            $avail_segs  = $subject->question_paper->segments()->pluck('id')->all();
                            $sel_segs   = array_intersect($request->segments, $avail_segs);
                            if(count($sel_segs)!=0 && count($sel_segs) < count($avail_segs))
                                $sync_arr[$subject->id]   = ['segments'=>implode(',', $sel_segs)];
                            else
                                array_push($delete_arr, $subject->id);

                        }
                        $message1   = $user->subjects()->syncWithoutDetaching($sync_arr);
                        $message    = $user->subjects()->detach($delete_arr);

                    } else {

                        $message1 = "";
                        $message = $user->subjects()->detach($request->values);    

                    }                    
                    //$message = $user->subjects()->detach($request->values);

                    return response(['status' => true, 'message' => $message, 'message1' => $message1]);
                }

            }
            if ($request->type === 'stoe') {
                $subject = Subject::find($request->id);
                if ($subject) {
                    
                    if(isset($request->segments) && count($request->segments)>0) {
                        
                        $sync_arr   = [];
                        $delete_arr = [];

                        $users      = User::whereIn('id',$request->values)->get();
                        foreach($users as $user) {

                            $users_segs = explode(",",$user->subjects()->where('subject_id',$subject->id)->first()->pivot->segments);
                            $sel_segs   = array_diff($request->segments, $users_segs);
                            if(count($sel_segs)!=0 && count($sel_segs) < count($avail_segs)){
                                $sync_arr[$user->id]   = ['segments'=>implode(',', $sel_segs)];
                            }
                            else {
                                array_push($delete_arr, $user->id);
                            }

                        }

                        $message1   = $subject->users()->syncWithoutDetaching($sync_arr);
                        $message    = $subject->users()->detach($delete_arr);

                    } else {

                        $message1 = "";
                        $message = $subject->users()->detach($request->values);

                    }  

                    //$message = $subject->users()->detach($request->values);

                    return response(['status' => true, 'message' => $message, 'message1' => $message1]);
                }
            }
        }

        return response(['status' => false, 'message' => 'Something went wrong']);

    }

    public function import(Request $request)
    {

        $request->validate([
                'evaluatormapping_detail' => 'required|file|mimes:xls,xlsx,csv|max:10240',
        ]);

        $random           = str_random(11);
        $ext              = $request->file('evaluatormapping_detail')->getClientOriginalExtension();
        $destination_path = storage_path('app/import_evaluatormapping/');

        if ( ! File::exists($destination_path)) { 
            File::makeDirectory($destination_path, 0777, true, true);
        }
        $request->file('evaluatormapping_detail')->move($destination_path, $random.'.'.$ext);
        
        $collection  = Excel::toArray(new Evaluatormapping, '/import_evaluatormapping/'.$random.'.'.$ext); 
        $enrollments = collect($collection[0])->all(); 
        
        /*$region     = Region::query();*/
      
        $insert = [];
        $update = 0;
        $errors = [];
        $i      = 2;

        foreach ($enrollments as $key => $value) 
        { 
            foreach(array_keys($value) as $keys) 
            {
                if (!empty($value[$keys]))
                {
                    $errors = [];
                    $row_error = '';
                    $has_error = 0;
                }  
            }
        }

        if(count($enrollments[0]) != 3)
        {
            $errors[] = 'Sheet Format Mismatch.'; 
            $has_error = 1;
        }

        if($has_error==0)
        {
        foreach ($enrollments as $key=>$enrollment) 
        {
            $row_error = '';
            $has_error = 0;

            $subject = Subject::where('subject_code', $enrollment['subject_id'])->first();
            if(!$subject)
            {   
                $has_error = 1;
                $row_error = 'Row '.$i.': The Subject Code mismatch';
            }

            if(!$has_error)
            {
                $user = User::select('users.id')->join('role_user', 'role_user.user_id', '=', 'users.id')->where('role_user.role_id', '=', '1')->where('users.username', '=', $enrollment['user_id'])->first();
                if(!$user)
                {   
                    $has_error = 1;
                    $row_error = 'Row '.$i.': The User Code mismatch';
                }
            }

            if(!$has_error)
            {
                $evaluator_mapping_detail = DB::table('subject_user')->where('subject_id', '=', $subject->id)->where('user_id', '=', $user->id)->first();

                if(!$evaluator_mapping_detail)
                {
                    $tmp['subject_id']                   = $subject->id;
                    $tmp['user_id']                      = $user->id;

                    $insert[] = $tmp;
                }
                else
                {
                    $has_error = 1;
                    $errors[]  = 'Row '.$i.': Mapping already exists';
                }
                
            }
            else
            {
                if($row_error != '')
                $errors[] = $row_error;
            }

            $i++;

        }
        }

        if(count($insert) && $has_error==0)
        {
            DB::table('subject_user')->insert($insert);
        }

        $message = "<b>Import Report :</b> <br><br> Newly Inserted : ".count($insert).'<br> Error Rows : '.count($errors);

        $message.='<br><br>'.implode("<br>", $errors);

        File::delete(storage_path('app/import_evaluatormapping/'.$random.'.'.$ext));

        return response()->json(['status'=>'success','message'=>$message]); 

    }

    public function download($file_name) 
    {
        
        $file_path = public_path('import_sample_file/'.$file_name);
        return response()->download($file_path);
    }

}