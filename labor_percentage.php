<?php
function labor_summary_post() {
  $ONEWEEKSECONDS = 604800;
  $ONEDAYSECONDS = 86400;

  $weeklimit = 0;	//weekly overtime hours threshold; 0 = no weekly OT consideration
  $daylimit = 0;	//daily overtime hours threshold; 0 = no daily OT consideration
  $payrollstart = 0; //day of the week payroll starts; Sun-Sat = 0-6

  $offset = 0; 	//time offset from UTC default in seconds

  $startDate = strtotime($this->post('startDate')); //unix timestamp of query start date
  $endDate = strtotime($this->post('endDate')); //unix timestamp of query end date

  if ($this->post('startTime')){	//optional
    $startTime = strtotime($this->post('startTime')); //unix timestamp of query start time
    $startminute = date('i', $startTime);	//0-59
    $starthour = date('H', $startTime);	//0-23
    $startseconds = date('s', $startTime); // 1-12
  }
  else{	//defaults to 00:00
    $startTime = 0;
    $startminute = 0;
    $starthour = 0;
    $startseconds = 0;
  }
  if($this->post('endTime')){	//end time provided
    $endTime = strtotime($this->post('endTime')); //unix timestamp of query end time
    $endminute = date('i', $endTime);
    $endhour = date('H', $endTime);
    $endseconds = date('s', $endTime);
  }else{	//defaults to 00:00
    $endTime = 0;
    $endminute = 0;
    $endhour = 0;
    $endseconds = 0;
  }

  $start = $startDate + ($starthour *3600) + ($startminute *60) + $startseconds; //query start in seconds
  $end = $endDate + ($endhour *3600) + ($endminute *60) + $endseconds;  //query end in seconds
  if($endTime == 0){  //only end date provided; assume end of day
    $end += $ONEDAYSECONDS;
  }

  $startz = $start - $offset; //start, timezone adjusted
  $endz = $end - $offset; //end, timezone adjusted

  //day of nearest, prior payroll start
  $startweekday = date('w', $startDate);
  if($startweekday < $payrollstart){
    $daystoprevpayroll = 7 - ($payrollstart - $startweekday);
  }
  else{
    $daystoprevpayroll = abs($payrollstart - $startweekday);
  }
  $timetoprevpayroll = $daystoprevpayroll * $ONEDAYSECONDS;
  $bufferedstarttime = ($startDate - $timetoprevpayroll) - $offset;	//most recent payroll start

  $query = //multiarray of Clock-In/Clock-Out staff records: Where bufferedstartime<=time<=endz
            //should exclude records with no clock out timestamp
  $employees = array(); //multiarray of shifts by employee
  $numShifts = count($query[0]);  //count of shift records
  //iterate through shifts
  for($i = 0; $i < $numShifts; $i++){
    $shift = $query[0][$i];
    $staffID = $shift['StaffID']; //employee identifier

    //arrange shifts by employee
    if(!array_key_exists($staffID, $employees)) {
      $employees[$staffID] = array();
    }
    array_push($employees[$staffID], $shift);
  }

  $totalRegHours = 0;	//sum of all regular hours worked by all employees
  $totalRegDollars = 0;	//sum of all regular dollars earned by all employees
  $totalOverHours = 0;	//sum of all overtime hours worked by all employees
  $totalOverDollars = 0;	//sum of all overtime dollars earned by all employees

  //iterate through each employee
  foreach ($employees as $employee){
    $weekindex = 1;	//current week in the iteration
    $dailyhourtotal = 0;	//sum of hours worked in current day
    $weeklyhourtotal = 0;	//sum of hours worked in current week
    $prevday = -1;	//tracks day of current shift

    //iterate through each shift of the current employee
    foreach($employee as $shift){
      $currentday = date('d', ($shift['ClockIn']));	//index of the shift's day
      $clockin = $shift['ClockIn']; //stamp of shift start
      $clockout = $shift['ClockOut']; //stamp of shift end
      $duration = $clockout - $clockin; //length of shift

      $regRate = $shift['RegRate']; //employee's regular pay rate for the shift
      $overRate = $shift['OverRate'];   //employees OT rate for the shift

      $include = false; //include shift in labor % calculation

      //new week, if considered
      if($weeklimit > 0 && $clockin > ($bufferedstarttime + ($ONEWEEKSECONDS * $weekindex))){
        $weeklyhourtotal = 0;
        $weekindex += 1;
      }

      //new day, if considered
      if($daylimit > 0 && $currentday != $prevday){
        $dailyhourtotal = 0;
      }

      //punches don't fall in search range, but employee was punched in
      if($clockin <= $startz && $clockout >= $endz){
        $duration = $duration - ($startz - $clockin)/3600 - ($clockout - $endz)/3600;
        $include = true;
      }
      //shift exceeds end date, truncate length
      else if($clockin >= $startz && $clockin <= $endz && $clockout >= $endz){
        $duration = $duration - ($clockout - $endz)/3600;
        $include = true;
      }
      //shift starts before search and enters range
      else if($clockin <= $startz && $clockout >= $startz && $clockout <= $endz){
        $duration = $duration - ($startz - $clockin)/3600;
        $include = true;
      }

      /* Calculate hours and pay  */

      //CASE 1: Both daily and weekly will exceed overtime
      if($weeklimit > 0 && $daylimit > 0
        && $dailyhourtotal <= $daylimit && $dailyhourtotal + $duration > $daylimit
        && $weeklyhourtotal <= $weeklimit && $weeklyhourtotal + $duration > $weeklimit){
        $dailydif = ($duration + $dailyhourtotal) - $daylimit;  //day amount that exceeds OT limit
        $weeklydif = ($duration + $weeklyhourtotal) - $weeklimit; //week amount that exceeds OT
        if($dailydif >= $weeklydif	//day hours remainder is greater
            && (($clockin >= $startz && $clockout <= $endz) || $include)){
          $totalRegHours += ($daylimit - $dailyhourtotal);
          $totalRegDollars += ($daylimit - $dailyhourtotal) * $regRate;
          $totalOverHours += $dailydif;
          $totalOverDollars += $dailydif * $overRate;
        }
        else if($weeklydif > $dailydif
            && (($clockin >= $startz && $clockout <= $endz) || $include)){
          $totalRegHours += $weeklimit - $weeklyhourtotal;
          $totalRegDollars += ($weeklimit - $weeklyhourtotal) * $regRate;
          $totalOverHours += $weeklydif;
          $totalOverDollars += $weeklydif * $overRate;
        }
      }
      //CASE 2: Already in weekly overtime
      else if($weeklimit > 0 && $weeklyhourtotal > $weeklimit
          && (($clockin >= $startz && $clockout <= $endz) || $include)){
        $totalOverHours += $duration;
        $totalOverDollars += $duration * $overRate;
      }
      //CASE 3: Already in daily overtime
      else if($daylimit > 0 && $dailyhourtotal > $daylimit
          && (($clockin >= $startz && $clockout <= $endz) || $include)){
        $totalOverHours += $duration;
        $totalOverDollars += $duration * $overRate;
      }
      //CASE 4: Will exceed weekly overtime
      else if($weeklimit > 0 && ($weeklyhourtotal + $duration) > $weeklimit
          && (($clockin >= $startz && $clockout <= $endz) || $include)){
        $totalRegHours += ($weeklimit - $weeklyhourtotal);
        $totalRegDollars += ($weeklimit - $weeklyhourtotal) * $regRate;
        $totalOverHours += ($weeklyhourtotal + $duration) - $weeklimit;
        $totalOverDollars += (($weeklyhourtotal + $duration) - $weeklimit) * $overRate;
      }
      //CASE 5: Will exceed daily overtime
      else if($daylimit > 0 && ($dailyhourtotal + $duration) > $daylimit
          && (($clockin >= $startz && $clockout <= $endz) || $include)){
        $totalRegHours += ($daylimit - $dailyhourtotal);
        $totalRegDollars += ($daylimit - $dailyhourtotal) * $regRate;
        $totalOverHours += ($dailyhourtotal + $duration) - $daylimit;
        $totalOverDollars += (($dailyhourtotal + $duration) - $daylimit) * $overRate;
      }
      //CASE 6: No overtime
      else if(($clockin >= $start - $offset && $clockout <= $endz) || $include){
        $totalRegHours += $duration;
        $totalRegDollars += $duration * $regRate;
      }
      $dailyhourtotal += $duration;	//update daily total w/ shift length
      $weeklyhourtotal += $duration;	//update weekly total w/ shift length
      $prevday = $currentday;
    }	//finished single shift
  }	//finished all of a single employee's shifts

$results = array();
$results['RegularHours'] = $totalRegHours;
$results['RegularDollars'] = $totalRegDollars;
$results['OvertimeHours'] = $totalOverHours;
$results['OvertimeDollars'] = $totalOverDollars;
$results['TotalDollars'] = $totalRegDollars + $totalOverDollars;

$salesquery = //DB query for total sales: Select sum(amount) From salesTbl Where: startz<=time<=endz
$totalSales = $salesquery[0];
$results['TotalSales'] = $totalSales;
if($totalSales == 0){ //no sales; labor is 100% of cost
  $results['LaborPercent'] = 100;
}
else{
  $results['LaborPercent'] = (($totalRegDollars + $totalOverDollars) / $totalSales) * 100;
}
$this->response($results);
}
