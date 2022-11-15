<?php 
namespace app\modules\sout2\models;

use app\constants\TypeDocTemplate;
use app\models\DocComments;
use app\models\Settings;
use Yii;
use yii\helpers\ArrayHelper;
use app\models\BaseModel;
use app\models\User;
use app\models\Managers;
use app\models\Clients;
use app\models\DocsTemplate;
use app\models\MailSender;
use app\modules\sout2\models\search\SoutKpSearch;

class SoutKpNew 
{
	public $kp_id_direction = [];
	private $fired = [];
	private $kps = [];
	private $clients_id = [];	
	private $clients = [];	
	private $managers_id = [];
	private $managers = [];
	private $users_id = [];
	private $users = [];
	private $posts = [];
	private $contracts = [];
	private $agreements = [];
	private $reqs = [];
	private $auto_tasks = [];
	private $forms = [];
	private $doc_comments = [];
	private $client_comments = [];
	private $contacts = [];
	private $mails = [];
	private $join = '';
	private $where = '';
	public $slice = [];
	public $count = 0;
	private $sort = 'id DESC';
	private $pref = '';
	private $pages = '';
	
	public function __construct($page, $sort, $num, $client, $manager, $status, $contract)
	{
		$this->page = $page;
		$this->pref = Yii::$app->getModule('sout2')->getPrefix();
		$count_pages = 50;
		$sql='';
		$this->getWhereNJoin($num, $client, $manager, $status, $contract);
		//подсчет количества записей и страниц
		$sql = "SELECT COUNT(*) FROM sout_kp ".$this->join." WHERE ".implode(" AND ", $this->where)."";	
		$row = Yii::$app->db->createCommand($sql)->queryOne();
		$this->count=$row['COUNT(*)'];
		$this->pages = ceil($this->count/$count_pages);
		$this->slice[]=($page-1)*$count_pages;
		$this->slice[]=$count_pages;
		if($this->count>0)
		{	
			$sql = "SELECT `sout_kp`.* FROM `sout_kp` ".$this->join." WHERE ".implode(" AND ", $this->where)." ORDER BY `sout_kp`.".$this->sort." LIMIT ".$this->slice[1]." OFFSET ".$this->slice[0];
			$rows = Yii::$app->db->createCommand($sql)->queryAll();
			foreach($rows as $row)
			{
				$this->kps[$row['id']]=$row;
				$this->kp_id_direction[]=$row['id'];
				$this->clients[$row['client_id']]=$row['client_id'];
				$this->managers_id[$row['manager_id']]=$row['manager_id'];
				$this->users[$row['author_id']]=$row['author_id'];
				$this->kps[$row['id']]['number']=$row['id']>751? $this->pref . $row['num'] . '-' . date('y', strtotime($row['created_at'])) . '-' . 'С' : $this->pref . 'КП' . '-' . $row['num'] . '-' . date('y', strtotime($row['created_at'])) . '-' . 'СОУТ';
			}
			//поиск уволеных менеджеров
			$this -> firedManagers ();
			if(count($this->kp_id_direction)>0)
			{
				//поиск договоров по кп
				$this -> getContracts();
				//поиск приложений по кп
				$this -> getAdds();
				//сделано ли КП по запросу из отдела продаж
				$this -> getReqs();
				//последние комментарии для КП
				$this -> getDc();
				//было ли отправлено КП
				$this -> getLtMails();
				//можно ли создать договор и приложение
				$this -> getAutoTasks();
			}
			//последний комментарий к контрагенту
			$this -> getCc();			
			//получение инфы о контрагентах
			$this -> getClients();
			//получение формы собственности
			$this -> getForms ();
			//получение менеджеров
			$this -> getManagers();
			//Получение должностей менеджеров
			$this -> getPosts();
			
			//контакты менеджеров
			$this -> getContacts();
			
			//Получение данных о пользователях
			$this -> getUsers();
			
		}
	}
	// Формирование первой ячейки строки
	// todo Необходимо будет перевести HTML код в отдельный файл и унифицировать его для других модулей
	public function getTd1($id)
	{
		$cnt = '<td><div class="btn-group dropdown-v2" style="float:left; margin:0px 10px 0px 0px;">
		<button data-toggle="dropdown" class="btn btn-sm btn-primary btn-color-none dropdown-toggle" style="">
		<i class="fa fa-gears"></i><i class="fa fa-caret-down"></i>				
		</button>
		<ul class="dropdown-menu " style="">
		<li class="">
			<a class="dropdown-item" href="pdf?id='.$id.'" data-target="" target="">
			<i class="zmdi zmdi-eye"></i> 
			Просмотр КП Pdf					</a>
		</li>
		<li class="">
			<a class="dropdown-item" href="update?id='.$id.'" data-target="" target="">
			<i class="zmdi zmdi-edit"></i> 
			Редактирование КП					</a>
		</li>
		<li class="">
			<a class="dropdown-item" href="send-mail?id='.$id.'" data-target="" target="">
			<i class="zmdi zmdi-email"></i> 
			Отправить КП					</a>
		</li>';
		if (!isset($this->auto_tasks[$id]))
		{
			$cnt .= '<li class="">
					<a class="dropdown-item create-contract-sout" href="#" data-target="'.$id.'" target="">
					<i class="zmdi zmdi-book"></i> 
					Создать договор					</a>
					</li>
					<li class="">
					<a class="dropdown-item" href="/sout2/additional-agreement/create?kp_id='.$id.'" data-target="" target="">
					<i class="zmdi zmdi-book-image"></i> 
					Создать приложение					</a>
					</li>';
		}
		if (Yii::$app->loadAccess->access(['labolatory', 'sout', 'kp','action', 'delete'], 'bool'))
		{
			$cnt .= '<li class="">
			<a class="dropdown-item btnDelete" href="delete?id='.$id.'" data-target="" target="">
			<i class="zmdi zmdi-delete"></i> 
			Удалить КП					</a>
			</li>';
		}
		if (!isset($this->auto_tasks[$id]) AND Yii::$app->loadAccess->access(['labolatory', 'sout', 'kp','action', 'delete'], 'bool'))
		{
			$cnt .= '<li class="">
					<a class="dropdown-item" href="delayed?doc_id='.$id.'" data-target="" target="">
					<i class="zmdi zmdi-block"></i> 
					Отказ					</a>
					</li>';
		}
		$cnt .= '</ul></div><a href="/sout2/kp/view?id='.$id.'">'.$this->kps[$id]['number'].'</a> от '.date('d.m.y', strtotime($this->kps[$id]['created_at'])).'<br>'.$this->users[$this->kps[$id]['author_id']]['name'];
		if (isset($this->reqs[$id]))
		{
			$cnt .= '<div style="float: left;width: 100%;border: 1px solid #e17777;padding: 5px;border-radius: 5px;background: #e69898;color: white;"><p>КП создано на основании запроса из отдела продаж</p><p><a style="color: white;text-decoration: underline;" href="/plugins?plug_name=sales_department&amp;action=comments-sout-kp&amp;req_id='.$this->reqs[$id]['id'].'">
			Запрос № '.$this->reqs[$id]['id'].' от 
			'.date('d.m.Y', $this->reqs[$id]['time_created']).'</a></p><p>Ответственный менеджер: 
			'.$this->users[$this->reqs[$id]['user_created']]['name'] .'</p></div>';
		}
		$cnt .= '</td>' ;
		return $cnt;
	}
	// Формирование второй ячейки строки
	// todo Необходимо будет перевести HTML код в отдельный файл и унифицировать его для других модулей
	public function getTd2($id)
	{
		$cnt = '<td><div class="detail-action new-style">
    	<span class="action-new">
		<a class="edit-btn btn-ajax-modal" href="/clients/update?id='.$this->kps[$id]['client_id'].'" data-target="#firstModal"><i class="zmdi zmdi-edit" style=""></i></a>
		</span>
		<a href="/clients/view?id='.$this->kps[$id]['client_id'].'">'.$this->forms[$this->clients[$this->kps[$id]['client_id']]['form_id']]['name'].' "'.$this->clients[$this->kps[$id]['client_id']]['name'].'"</a>	</div>
		<div class="client-comment" style="padding: 5px 0px 0px 10px;">';
		if(isset($this -> client_comments[$this->kps[$id]['client_id']]['text']))
		{
			$cnt .=date('d.m.y', strtotime($this -> client_comments[$this->kps[$id]['client_id']]['created_at'])).' '.$this->users[$this -> client_comments[$this->kps[$id]['client_id']]['author_id']]['name'].':<br>'.$this -> client_comments[$this->kps[$id]['client_id']]['text'];
		}
		$cnt .= '</div><div class="client-comment">';
		if (isset($this->doc_comments[$id]))
		{
			$cnt.= date('d.m.y', strtotime($this->doc_comments[$id]['created_at'])).' '.$this->users[$this->doc_comments[$this->kps[$id]['id']]['author_id']]['name'].':<br>'.$this->doc_comments[$id]['text'];
		}
		$cnt .= '</div></td>';
		return $cnt;
	}
	// Формирование третьей ячейки строки
	// todo Необходимо будет перевести HTML код в отдельный файл и унифицировать его для других модулей
	public function getTd3($id)
	{
		$cnt ='<td>
		<div class="TableContactInfoManager" style="width:100%;float:left;">
		<div style="width:80%;float: left;">
		<p>
		<a href="/client/manager/view?id='.$this->kps[$id]['manager_id'].'" target="_blank" style="font-weight: bold;">';
		if($this->managers[$this->kps[$id]['manager_id']]['post_id']!=11)
		{
			$cnt .= $this->posts[$this->managers[$this->kps[$id]['manager_id']]['post_id']]['name'].' - ';
		}
		$cnt .= $this->managers[$this->kps[$id]['manager_id']]['name'].'</a></p>';
		if(isset($this->fired[$this->kps[$id]['manager_id']][$this->kps[$id]['client_id']])) 
		{
			$cnt .= '<p><span class="label label-danger">Сотрудник уволен из организации: '.$this->fired[$this->kps[$id]['manager_id']][$this->kps[$id]['client_id']].'</span></p>';
		}
		if (isset($this->contacts[3][$this->kps[$id]['manager_id']]))
		{
			foreach($this->contacts[3][$this->kps[$id]['manager_id']] as $email)
			{
				$cnt .= '<p>
				<a href="mailto:'.$email['contact'].'">
				<i class="fa fa-envelope-o" aria-hidden="true"></i> '.$email['contact'].($email['name']!=''?' ('.$email['name'].')':'').'</a>
				</p>';
			}
		}
		$cnt .= '</div><div style="width:20%;float: left;">';
		if(isset($this->contacts[2][$this->kps[$id]['manager_id']]) OR isset($this->contacts[1][$this->kps[$id]['manager_id']]))
		{
			$cnt .= '<div class="btn-group dropdown-v2" style="float:right;">
			<button data-toggle="dropdown" class="btn btn-sm btn-primary btn-color-none dropdown-toggle" style="" aria-expanded="false">
			<i class="fa fa fa-phone"></i> 
			<i class="fa fa-caret-down"></i>				
			</button>
			<ul class="dropdown-menu " style="right: 0px; left: auto;">';
			if(isset($this->contacts[2][$this->kps[$id]['manager_id']]))
			{
				foreach($this->contacts[2][$this->kps[$id]['manager_id']] as $phone){
				$cnt .= '<li class="">
				<a class="dropdown-item createCall" href="/plugins?plug_name=integrate_freepbx&amp;action=createCall&amp;source=sout_kp_table&amp;source_id='.$this->kps[$id]['id'].'&amp;phone='.$phone['contact'].'" data-target="" target="">
				<i class=""></i>'.$phone['contact'].' ('.$phone['name'].($phone['description']!=''?', '.$phone['description']:'').')</a></li>';
			}
			}
			if(isset($this->contacts[1][$this->kps[$id]['manager_id']]))
			{
				foreach($this->contacts[1][$this->kps[$id]['manager_id']] as $phone){
				$cnt .= '<li class="">
				<a class="dropdown-item createCall" href="/plugins?plug_name=integrate_freepbx&amp;action=createCall&amp;source=sout_kp_table&amp;source_id='.$this->kps[$id]['id'].'&amp;phone='.$phone['contact'].'" data-target="" target=""><i class=""></i> '.$phone['contact'].' ('.$phone['name'].($phone['description']>''?', '.$phone['description']:'').')</a></li>';
			}}
			$cnt .='</ul></div>';
		}
		$cnt .= '</div></div></td>';
		return $cnt;
	}
	// Формирование четвертой ячейки строки
	// todo Необходимо будет перевести HTML код в отдельный файл и унифицировать его для других модулей
	public function getTd4($id)
	{
		$cnt = '<td>';
		if($this->kps[$id]['status'] != 2 AND isset($this->mails[$this->kps[$id]['id']]))
		{
			$cnt.='<p>Отправлен<br>';
			$cnt.=  date(\app\constants\GC::DATE_FORMAT, $this->mails[$this->kps[$id]['id']]['time']).'<br>'.
			$this->users[$this->mails[$this->kps[$id]['id']]['user_id']]['name'];
			$cnt.= '</p>';
		}
		else if($this->kps[$id]['status'] == 1) 
		{
			$cnt.='<p>Не отправлен</p>';
		}
		else if($this->kps[$id]['status'] == 2) 
		{
			$cnt.='<p>Отправлен<br>';
			if(isset($this->mails[$id]))
			{
				$cnt.= date(\app\constants\GC::DATE_FORMAT, $this->mails[$id]['time']).'<br>'.$this->users[$this->mails[$id]['user_id']]['name'];
			}
			$cnt.= '</p>';
		}
		else if($this->kps[$id]['status'] == 3) 
		{
			$cnt.='<p>Изменен но не отправлен</p>';
		}
		$cnt .= '</td>';
		return $cnt;
	}
	// Формирование пятой ячейки строки
	// todo Необходимо будет перевести HTML код в отдельный файл и унифицировать его для других модулей
	public function getTd5($id)
	{
		$cnt ='<td>';
		if (isset($this->contracts[$id]))
		{
			$cnt .= '<a href="/sout2/contracts/view?id='.$this->contracts[$id]['id'].'">№ '.$this->contracts[$id]['number'].'</a> от ' . date('d.m.y', strtotime($this->contracts[$id]['date']));
		}
		else
		{
			$cnt .= '<div class="btn-group dropdown-v2" style="" title="Действия">
					<button data-toggle="dropdown" class="btn btn-sm btn-primary dropdown-toggle">
					<i class="zmdi zmdi-format-list-bulleted"></i></button>
					<ul class="dropdown-menu dropdown-menu-left-2"><li><a class="dropdown-item" href="/sout2/kp/send-mail?id='.$id.'" title="Отправить КП СОУТ" style=""><i class="zmdi zmdi-email"></i> Отправить КП СОУТ</a></li>';
			if (isset($this->auto_tasks[$id]))
			{
				$cnt .= '<li><a href="#" class="dropdown-item create-contract-sout" title="Создать договор СОУТ" data-target="'.$id.'"><i class="zmdi zmdi-book"></i>Создать договор СОУТ</a></li><li><a class="dropdown-item" href="/sout2/additional-agreement/create?kp_id='.$id.'" title="Создать приложение СОУТ" style=""><i class="zmdi zmdi-book-image"></i> Создать приложение СОУТ</a></li></ul>
									</div>';
			}
			else
			{
				$cnt .= '</ul></div>';
			}
		}
		$cnt.= '</td>';
		return $cnt;
	}
	// Формирование строки с количеством найденных данных
	// todo Необходимо будет перевести HTML код в отдельный файл и унифицировать его для других модулей
	public function records()
	{
		$cnt = '';
		if($this->count>0)
		{
			$cnt .= '<div><div id="w1" ><div class="summary">Показаны записи <b>';
			$cnt .= $this->slice[0]+1 .'-'. ($this->slice[0]+count($this->kp_id_direction)).'</b> из <b>'.$this->count.'</b>.</div>';
		}
		return $cnt;
	}
	// Формирование строки с пагинацией
	// todo Необходимо будет перевести HTML код в отдельный файл и унифицировать его для других модулей
	public function pagination()
	{
		
		$cnt = '';
		if ($this->pages > 1) { 
			$cnt .= '<ul class="pagination"><li class="prev"><a class="moveToPage" style="cursor: pointer" data-page="1"><span>«</span></a></li>';
			$start = 1;
			$end = $this->pages>10?10:$this->pages;
			if($this->pages > 10){
				$start = $this->page - 4;
				$end = $this->page + 5;
				if($start < 1)
				{
					$end = $this->page + 6 + abs($start); 
					$start = 1;
				}
				if($end > $this->pages)
				{
					$end = $this->pages;
					$start = $this->pages - 9;
				}
			}
			for($i = $start; $i <= $end; $i++) {
				$cnt .= '<li';
				if($i == $this->page){
					$cnt .= ' class="active"';
				}
				$cnt .= '><a class="moveToPage" style="cursor: pointer" data-page="'.$i.'">'.$i.'</a></li>';
			 } 
			$cnt .= '<li class="next"><a class="moveToPage" style="cursor: pointer" data-page="<?=$pages?>">»</a></li></ul>';
		}
		return $cnt;
	}
	//получение массива индексов в отсортированном порядке
	public function direction()
	{
		return $this -> kp_id_direction;
	}
	//получение класса для ячейки
	public function classTr($id)
	{
		if($this->kps[$id]['color']=='red')
		{
			return 'danger';
		}
		else if(isset($this->contracts[$id]) OR isset($this->agreements[$id]))
		{
			return 'success';
		}
		else
		{
			return '';
		}
	}
	//определение необходимой сортировки
	private function getSort($sort)
	{
		switch ($sort){
			case 'num':
				$this -> sort = 'num ASC';
				break;
			case '-num':
				$this -> sort = 'num DESC';
				break;
			case 'status':
				$this -> sort = 'status ASC';
				break;
			case '-status':
				$this -> sort = 'status DESC';
				break;
		}
	}
	//определение необходимой сортировки/поиска
	private function getWhereNJoin($num, $client, $manager, $status, $contract)
	{
		$this->where[]='(`sout_kp`.`deleted`=0)';
		$join = '';
		// по номеру
		if($num!='')
		{
			$this->where[]='(`sout_kp`.`num`='.$num.')';
		}
		//по статусу
		if($status!='')
		{
			$this->where[]='(`sout_kp`.`status`='.$status.')';
		}
		//по договору
		if($contract==3)
		{
			$this->where[]='(`sout_kp`.`color`="red")';
		}
		else if ($contract==2)
		{
			$this->join .= 'INNER JOIN `sout_contracts` ON `sout_contracts`.kp_id = `sout_kp`.id';
		}
		else if ($contract==1)
		{
			$this->join .= 'LEFT OUTER JOIN `sout_contracts` ON `sout_contracts`.kp_id = `sout_kp`.id';
			$this->where[] = '(`sout_kp`.`color` IS NULL)';
		}
		
		//по контрагенту 
		if($client!='')
		{
			$this->where[]='(UPPER(clients.name) LIKE UPPER("%'.$client.'%") OR UPPER(clients.full_title) LIKE UPPER("%'.$client.'%"))';
			$this->join .= ' LEFT JOIN `clients` ON `sout_kp`.`client_id` = `clients`.`id` ';
		}
		//по менеджеру 
		if($manager!='')
		{
			$this->where[]='(UPPER(managers.name) LIKE UPPER("%'.$manager.'%"))';
			$this->join .= ' LEFT JOIN `managers` ON `sout_kp`.`manager_id` = `managers`.`id` ';
		}
			
		// проверка по доступу к записям
		if (Yii::$app->loadAccess->access(['labolatory', 'sout', 'kp', 'view'], 'string') == 'one') {
			$this->where[]='(`sout_kp`.`author_id`='.Yii::$app->user->id.')';
		}
	}
	//нахождение уволенных контактных лиц из выбранных
	private function firedManagers ()
	{
		$sql = 'SELECT * FROM client_managers WHERE date_fire>1 AND manager_id IN ('.implode(", ", $this->managers_id).')';
		$rows = Yii::$app->db->createCommand($sql)->queryAll();
		foreach ($rows as $row)
		{
			$this->fired[$row['manager_id']][$row['client_id']]=$row['reason_fire'];
		}	
	}
	//получение договоров по выбранным коммерческим предложениям
	private function getContracts ()
	{
		$sql='SELECT * FROM sout_contracts WHERE kp_id in ('.implode(',', $this->kp_id_direction).')';
		$rows = Yii::$app->db->createCommand($sql)->queryAll();
		
		foreach($rows as $row)
		{
			$number = '';
			if ($row['id'] > 243) {
				if (!$row['custom_num'] AND $row['custom_num'] != '') {
					$number = $row['custom_num'];
				} else {
					$number = $this->pref  . $row['num'] . '-' . date('y', strtotime($row['created_at'])) . '-' . 'С';
				}
				
			} else {
				$number =  $this->pref . 'Д' . '-' . $row['num'] . '-' . date('y', strtotime($row['created_at'])) . '-' . 'СОУТ';
			}
			$this->contracts[$row['kp_id']]=$row;
			$this->contracts[$row['kp_id']]['number']=$number;			
			$this->users[$row['author_id']]=$row['author_id'];
		}
	}
	
	private function getAdds ()
	{
		$sql='SELECT * FROM sout_additional_agreement WHERE kp_id in ('.implode(',', $this->kp_id_direction).')';
		 
		$rows = Yii::$app->db->createCommand($sql)->queryAll();
		foreach($rows as $row)
		{
			$this->agreements[$row['kp_id']]=$row;	
			$this->users[$row['author_id']]=$row['author_id'];
		}
	}
	//получение приложений по выбранным коммерческим предложениям
	private function getReqs ()
	{
		$sql='SELECT * FROM plugins_sales_department_req_kp_sout WHERE kp_id in ('.implode(', ', $this->kp_id_direction).')';		 
		$rows = Yii::$app->db->createCommand($sql)->queryAll();
		foreach($rows as $row)
		{
			$this->reqs[$row['kp_id']]=$row;	
			$this->users[$row['user_created']]=$row['user_created'];
		}
	}
	//получение комментариев к выбранным коммерческим предложениям
	private function getDc ()
	{
		$sql='SELECT * FROM doc_comments WHERE type_doc=0 and id_doc in ('.implode(', ', $this->kp_id_direction).')';
		 
		$rows = Yii::$app->db->createCommand($sql)->queryAll();
		foreach($rows as $row)
		{
			$this->doc_comments[$row['id_doc']][$row['id']]=$row;	
			$this->users[$row['author_id']]=$row['author_id'];
		}
		
		foreach($this->doc_comments as $key=>$row)
		{
			$comment=end($row);
			unset($this->doc_comments[$key]);
			$this->doc_comments[$key]=$comment;
		}
	}
	//получение данных по отправленным письмам из выбранных КП
	private function getLtMails ()
	{
		$sql = 'SELECT * FROM history WHERE  `table` = "sout_kp" and row_id in ('.implode(', ', $this->kp_id_direction).') and description like "%отправил письмо по адресам%"';
		$rows = Yii::$app->db->createCommand($sql)->queryAll();
		foreach($rows as $row)
		{
			$this->mails[$row['row_id']] = $row;
			$this->users[$row['user_id']]=$row['user_id'];
		}		
	}
	//Получение созданных автозадач на основе КП
	private function getAutoTasks ()
	{
		$sql = "SELECT doc_id FROM auto_tasks WHERE (result = 0 OR result IS NULL) AND doc_id in (".implode(',', $this->kp_id_direction).")";			
		
		$rows = Yii::$app->db->createCommand($sql)->queryAll();
		foreach ($rows as $row)
		{
			$this->auto_tasks[$row["doc_id"]] = $row["doc_id"];
		}		
	}
	//комменатрии к контрагентам КП
	private function getCc ()
	{
		$sql='SELECT * FROM clientcomments WHERE client_id in ('.implode(',', $this->clients).')';
		 
		$rows = Yii::$app->db->createCommand($sql)->queryAll();
		foreach($rows as $row)
		{
			$this->client_comments[$row['client_id']][$row['id']]=$row;	
			$this->users[$row['author_id']]=$row['author_id'];
		}
		foreach($this->client_comments as $key=>$row)
		{
			$comment=end($row);
			unset($this->client_comments[$key]);
			$this->client_comments[$key]=$comment;
		}
	}
	//контрагенты
	private function getClients ()
	{
		$sql='SELECT * FROM clients WHERE id in ('.implode(',', $this->clients).')';
		$this->clients = [];
		$rows = Yii::$app->db->createCommand($sql)->queryAll();
		foreach($rows as $row)
		{
			$this->clients[$row['id']]=$row;	
			$this->forms[$row['form_id']] = $row['form_id'];
		}
	}
	//Формы записей для контрагентов
	private function getForms ()
	{
		$sql = 'SELECT * FROM client_form WHERE id in ('.implode(',', $this->forms).')';
		$rows = Yii::$app->db->createCommand($sql)->queryAll();
		$this->forms = [];
		foreach($rows as $row)
		{
			$this->forms[$row['id']]=$row;	
		}
	}
	//Получение контактных лиц для выбранных контрагентов
	private function getManagers ()
	{
		$sql='SELECT * FROM managers WHERE id in ('.implode(',', $this->managers_id).')';		
		$rows = Yii::$app->db->createCommand($sql)->queryAll();
		foreach($rows as $row)
		{
			$this->managers[$row['id']]=$row;	
			$this->posts[$row["post_id"]] = $row["post_id"];
		}
	}
	//контакты самих контактных лиц
	private function getContacts ()
	{
		$this->contacts=[1=>[],2=>[],3=>[]];
		$sql='SELECT * FROM manager_contacts WHERE manager_id in ('.implode(',', $this->managers_id).') AND (type=3 or type=2 or type=1)';
		 
		$rows = Yii::$app->db->createCommand($sql)->queryAll();
		foreach($rows as $row)
		{
			$this->contacts[$row['type']][$row['manager_id']][]=$row;	
		}
	}
	//получение данных о авторах и ответственных пользователях
	private function getUsers ()
	{
		$sql='SELECT * FROM user LEFT JOIN user_profiles ON user.id = user_profiles.user_id WHERE id in ('.implode(',', $this->users).')';
		$this->users = [];
		$rows = Yii::$app->db->createCommand($sql)->queryAll();
		foreach($rows as $row)
		{
			$this->users[$row['id']]=$row;	
		} 
	}
	//должности контактных лиц контрагентов
	private function getPosts ()
	{
		$sql = "SELECT id, name FROM posts WHERE id IN (".implode(",", $this->posts).")";
		$rows = Yii::$app->db->createCommand($sql)->queryAll();
		$this->posts = [];
		foreach ($rows as $row)
		{
			$this->posts[$row["id"]]["name"] = $row["name"];
		}
	}
}
?>