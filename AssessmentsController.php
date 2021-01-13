<?php

namespace App\Http\Controllers;

use App\ImmigrationAssessment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Locale;

class AssessmentsController extends Controller
{
	private $validatorRedirectUrl=null;

	private $formType=null;
	/**
	 * @var array the validations
	 */
	private $validations=array(
		'first_name'					=>	'required_if:form,canada,usa,contact,pre_consult',
		'form'							=>	'required',
		'email'							=>	'email',

		'last_name'						=>	'required_if:form,canada,usa,pre_consult',
		'phone_number'					=>	'required_if:form,canada,usa,contact,pre_consult',
		'interested_in'					=>	'required_if:form,canada,usa',
		'marital_status'				=>	'required_if:form,canada,usa',
		'age'							=>	'required_if:form,canada,usa,pre_consult',
		'nationality'					=>	'required_if:form,canada,usa,pre_consult',
		'criminal_offence'				=>	'required_if:form,canada,usa',

		'criminal_offence_description'	=>	'required_if:criminal_offence,yes',

		'message'						=>	'required_if:form,contact',

	);
	private $preconsultValidations=array(
		'page'								=>	'required',
		'where_would_you_like_to_go'		=>	'required_if:page,1',
		'type_of_immigration_interested_in'	=>	'required_if:page,2',
		'age'								=>	'required_if:page,3',
		'marriage_status'					=>	'required_if:page,3',
		'age_of_spouse'						=>	'required_if:marriage_status,Married or Common Law Partnership',
		'nationality'						=>	'required_if:page,3',
		'current_country'					=>	'required_if:page,3',
		'situation'							=>	'required_if:page,3',
		'languages'							=>	'required_if:page,3'

	);
	/**
	 * @var array These are the fields we won't include in "other_fields" which we json encode into the db.
	 */
	private $defaultFields=array(
		'first_name',
		'form',
		'email',
		'last_name',
		'phone_number',
		'interested_in',
		'marital_status',
		'age',
		'nationality',
		'criminal_offence',
		'contact_times_1',
		'contact_times_2',
		'contact_times_3',
		'contact_times_4',
		'contact_method',
		'tags',
		'newsletter',
		'_token',
		'criminal_offence_description'
	);
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
    	if(isset($request->form))
    		$this->formType=$request->form;
    	//special work for pre-consult since stuff is stored in the cookies in that one
		if(strcmp($request->form,'pre_consult')===0)
		{
			$pages=$request->session()->get('pre-consult.page');
			foreach($pages as $page)
				$request->request->add($page);
			$request->request->add(array('just_draw_view'=>true));//prevent validation on exceptions if validation fails on this page
			$this->validate($request,
				$this->validations);
			$request->request->remove('just_draw_view');
		}
		else
		{
			$this->validate($request,
				$this->validations);
		}
		//dd($request);

    	//create entry in db for immigration assessment, kick off note to track server to do all the CRM magic it needs to do
		$ia=new ImmigrationAssessment(array(),$request);
		$ia->ip=$_SERVER['REMOTE_ADDR'];
		$ia->visitor_id=array_key_exists('visitor_id',$_COOKIE)!==false?$_COOKIE['visitor_id']:null;
		$ia->first_name=$request->first_name;
		$ia->last_name=$request->last_name;
		$ia->email=$request->email;
		$ia->phone_number=$request->phone_number;
		$ia->contact_times=$request->contact_times_1." ".$request->contact_times_2." ".$request->contact_times_3." ".$request->contact_times_4;
		$ia->contact_method=$request->contact_method;
		$ia->interested_in=$request->interested_in;
		$ia->marital_status=$request->marital_status;
		$ia->age=$request->age;
		$ia->nationality=$request->nationality;
		if(isset($request->criminal_offence))
		{
			$co=strtolower($request->criminal_offence);
			if(strcmp($co,'yes')===0)
			{
				$ia->criminal_offence=true;
				$ia->criminal_offence_details=$request->criminal_offence_description;
			}
			else
				$ia->criminal_offence=false;

		}
		$redirectLocation='/thank-you/';
		if(isset($request->form))	//we can figure out additional tags
		{
			$form=strtolower($request->form);
			$ia->form=$form;
			if((strcmp($form,'canada')===0))	//assessment forms
			{
				$redirectLocation='/thank-you-book-your-consultation-now/';
				$ia->tags='new-weblead,weblead,vca-lead';
				if(isset($request->newsletter))	//they don't want the newsletter
					$ia->tags.=',VP-Subscribed-NL';
			}elseif((strcmp($form,'usa')===0)){
				$redirectLocation='/thank-you-book-your-consultation-now/';
				$ia->tags='new-weblead,weblead,us-lead';
				if(isset($request->newsletter))	//they don't want the newsletter
					$ia->tags.=',VP-Subscribed-NL';
			}
			elseif((strcmp($form,'newsletter')===0)||(strcmp($form,'newsletter_full')===0))	//newsletter form
			{
				//$redirectLocation='/iframe-thankyou/';
                                $redirectLocation='/thank-you-for-subscribing/';

				$ia->tags='VP-Subscribed-NL';
			}
			elseif((strcmp($form,'contact')===0))
				$ia->tags='New-WebLead';
		}
		//lets loop through any other random fields the user has provided
		$otherFields=new \stdClass();
		foreach($request->request as $key=>$value)
		{
			if(array_search($key,$this->defaultFields)===false)	//don't enter the default fields into the other fields data
				$otherFields->$key=$value;
		}
		if(array_key_exists('HTTP_ACCEPT_LANGUAGE',$_SERVER))
			$otherFields->prefered_language=$_SERVER['HTTP_ACCEPT_LANGUAGE'];
		$ia->other_fields=json_encode($otherFields);
		$ia->save();
		return redirect($redirectLocation);
    }

    public function preConsult($subStep, Request $request)
	{
		if($request->request->has('button_previous'))//they want to go back to the previous page, fake out a validation error to facilitate this
		{
			$previousStep=($subStep-2);
			$sessionKey='pre-consult.page.'.$previousStep;
			$this->validatorRedirectUrl='/pre-consult/'.$previousStep;
			$validator = $this->getValidationFactory()->make($request->all(), $this->preconsultValidations);
			$request->request->add(array_merge($request->session()->get($sessionKey),array('just_draw_view'=>true)));
			$request->request->remove('button_previous');
			$this->throwValidationException($request, $validator);
		}
		//check if this is a request for the previous page, we don't do any validation or storage in this case but just render the previous page
		if(
			($request->getSession()->has('_old_input')===false)||
			(array_key_exists('just_draw_view',$request->getSession()->get('_old_input'))===false)
		)
		{
			$request->request->add(array('just_draw_view'=>true));//prevent validation on exceptions if validation fails on this page
			$this->validate($request,
				$this->preconsultValidations,
				array(
					'required' => 'Enter a value',
					'required_if' => 'Enter a value',
					'email' => 'Enter a valid email'
				));
			$request->request->remove('just_draw_view');
			//validation passed, store the sent parameters from the request in the sessions
			$previousStep=$subStep-1;
			$sessionKey='pre-consult.page.'.$previousStep;
			$request->request->remove('_token');
			$request->request->remove('page');
			$request->session()->put($sessionKey,$request->input());
		}
		return view('pages.pre-consult.step'.$subStep)->with('suppressNewsletter', true);
	}
    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
//override to provide a special redirect for newsletter to the full newsletter page
	protected function getRedirectUrl()
	{
		if($this->validatorRedirectUrl!==null)	//in the case of a specific page to render from pre-consult page
		{
			$redirectUrl=$this->validatorRedirectUrl;
			$this->validatorRedirectUrl=null;
			return $redirectUrl;
		}
		else if(strcmp($this->formType,'newsletter')===0)	//special case for any newsletter submission, we want to take them to the full newsletter page instead of where they where
			return "/newsletter/";
		return parent::getRedirectUrl();//otherwise, standard behaviour
	}
}
