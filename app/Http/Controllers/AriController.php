<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AriController extends Controller {
	public function showPartStream() {
		return view('ari.partstream');
	}
}
