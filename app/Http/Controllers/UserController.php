<?php

namespace App\Http\Controllers;

use App\Person;

use Illuminate\Http\Request;
use File;
use Auth;
use DB;
use Validator;
use Exception;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

class UserController extends Controller {
    public function __construct() {
    }

    public function makeDirectory($path, $mode, $recursive, $force) {
        if ($force) {
            return @mkdir($path, $mode, $recursive);
        } else {
            return mkdir($path, $mode, $recursive);
        }
    }

    public function index(Request $request) {
        $items = DB::select("
            SELECT *
            FROM person
            ORDER BY id ASC
        ");

        // Get the current page from the url if it's not set default to 1
        empty($request->page) ? $page = 1 : $page = $request->page;

        // Number of items per page
        empty($request->rpp) ? $perPage = 10 : $perPage = $request->rpp;
        
        $offSet = ($page * $perPage) - $perPage; // Start displaying items from this number

        // Get only the items you need using array_slice (only get 10 items since that's what you need)
        $itemsForCurrentPage = array_slice($items, $offSet, $perPage, false);

        // Return the paginator with only 10 items but with the count of all items and set the it on the correct page
        $result = new LengthAwarePaginator($itemsForCurrentPage, count($items), $perPage, $page);

        return response()->json($result);
    }

    public function show($id) {
        try {
            $item = Person::where('id', $id)->firstOrFail();
            $previos = Person::select('id')->where('id', '<', $id)->orderBy('id', 'DESC')->first();
            $next = Person::select('id')->where('id', '>', $id)->orderBy('id', 'ASC')->first();
            $item->previos_id = $previos['id'];
            $item->next_id = $next['id'];
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 404, 'data' => 'Person not found.']);
        }

        return response()->json($item);
    }

    public function store(Request $filesdata) {
        $request = (object)json_decode($filesdata->data);

        DB::beginTransaction();
        $errors = [];
        $errors_validator = [];
        $lastId = null;

        $validator = Validator::make([
            'name' => $request->name,
            'nick_name' => $request->nick_name,
            'age' => $request->age,
            'skill' => $request->skill
        ], [
            'name' => 'required|max:255',
            'nick_name' => 'required|max:255',
            'age' => 'integer',
            'skill' => 'required'
        ]);

        if ($validator->fails()) {
            $errors_validator[] = $validator->errors();
        }

        if (!empty($errors_validator)) {
            return response()->json(['status' => 400, 'data' => $errors_validator]);
        }

        $skill = '';
        foreach ($request->skill as $sk) {
            $skill .= $sk.',';
        }

        if(empty($request->id)) {
            $item = new Person;
        } else {
            $item = Person::find($request->id);
        }

        $item->name = $request->name;
        $item->nick_name = $request->nick_name;
        $item->age = $request->age;
        $item->skill = $skill;
        $item->line = $request->line;
        $item->tel = $request->tel;
        $item->detail = $request->detail;
        try {
            $item->save();
            $lastId = empty($request->id) ? DB::getPdo()->lastInsertId(): $request->id;

            $path_folder = base_path() . '/public/uploads/brand/'. $lastId;
            if (!File::exists($path_folder)) {
                $this->makeDirectory($path_folder, 0777, true, true); // create folder
            }
            foreach ($filesdata->file() as $f) {
                Person::where('id', $lastId)->update(['image' => date('Ymd_His').$f->getClientOriginalName()]);
                $fileName = iconv('UTF-8','windows-874', date('Ymd_His').$f->getClientOriginalName()); // set folder name utf8
                $f->move($path_folder, $fileName); //add image to folder
                // $this->resize_image($path, 200, 200);
            }
        } catch (Exception $e) {
            $errors[] = substr($e, 255);
        }

        if(empty($errors)) {
            DB::commit();
            $status = 200;
        } else {
            DB::rollback();
            $status = 400;
        }

        return response()->json(['status' => $status, 'data' => $errors, 'last_id' => $lastId]);
    }

    function resize_image($file, $w, $h, $crop=FALSE) {
        list($width, $height) = getimagesize($file);
        $r = $width / $height;
        if ($crop) {
            if ($width > $height) {
                $width = ceil($width-($width*abs($r-$w/$h)));
            } else {
                $height = ceil($height-($height*abs($r-$w/$h)));
            }
            $newwidth = $w;
            $newheight = $h;
        } else {
            if ($w/$h > $r) {
                $newwidth = $h*$r;
                $newheight = $h;
            } else {
                $newheight = $w/$r;
                $newwidth = $w;
            }
        }
        $src = imagecreatefromjpeg($file);
        $dst = imagecreatetruecolor($newwidth, $newheight);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newwidth, $newheight, $width, $height);
    
        return $dst;
    }

    public function destroy($id) {
        try {
            $item = Person::where('id', $id)->firstOrFail();
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 404, 'data' => 'Person not found.']);
        }

        try {
            $item->delete();
        } catch (Exception $e) {
            if ($e->errorInfo[1] == 1451) {
                return response()->json(['status' => 400, 'data' => 'Cannot delete because this User is in use.']);
            } else {
                return response()->json($e->errorInfo);
            }
        }

        return response()->json(['status' => 200]);
    }
}