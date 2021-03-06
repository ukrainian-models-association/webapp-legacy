<?php
load::app('modules/adminka/controller');
class adminka_pages_action extends adminka_controller {
    
    public function execute() {

        pages_peer::set_template('<div style="border-bottom: 1px solid #DEE4F0; padding: 5px 0;" class="left tree_item_box" rel="parent_%level%">
                                <div style="width: 230px; " class="left">
                                    <div class="tree_item" style="margin-left: %margin%px; line-height: 16px; font-size: 12px;" class="%selected%">
                                        <a style="%style%" href="/adminka/pages?link=%link%">%title%</a>
                                    </div>
                                </div>
                                <div style="" class="left pl20">
                                    <a href="javascript:void(0);" title="Вверх" onClick="change_item(%id%,\'move\',{direction: 1})"><img src="/ui/up.png"></a>
                                    <a href="javascript:void(0);" title="Вниз" onClick="change_item(%id%,\'move\',{direction: 0})"><img src="/ui/down.png"></a>
                                    <a href="javascript:void(0);" title="Удалить" onClick="change_item(%id%,\'delete\')"><img src="/ui/delete.png"></a>
                                    <a href="/page?link=%link%" title="Просмотреть"><img src="/ui/preview.png" style="width: 16px; height: 16px;"></a>
                                </div>
                            </div>
                            <div class="clear"></div>');
        $nodes = pages_peer::instance()->get_list(array('parent_id'=>0),array(),array('weight ASC'));
        $this->tree = pages_peer::instance()->build_tree($nodes);
	
	$this->link = request::get('link');
        $this->list = pages_peer::instance()->get_list();
        
        $all_pages = db::get_rows("SELECT * FROM pages");
        if(!empty($all_pages))
            foreach ($all_pages as $k => $v) {
                $title = unserialize($v['title']);
                $this->all_pages[$v['id']] = $title['ru'];
            }
            
        if($this->link) 
                if($item_id = pages_peer::instance ()->get_by_url($this->link))
                        $this->page_data = pages_peer::instance ()->get_item($item_id);
        $this->parent = ($this->page_data['parent_id']) ? $this->page_data['parent_id'] : 0;
            
        if(request::get('tree')) die(pages_peer::instance()->display($this->tree,'ru'));
        if(request::get('get_data')) {
            if($this->page_data) {
                $tmp = unserialize($this->page_data['content']);
                $this->page_data['content'] = array(
                            'ru' => stripslashes($tmp['ru']),
                            'en' => stripslashes($tmp['en'])
                    );
                $tmp = unserialize($this->page_data['title']);
                $this->page_data['title'] = array(
                            'ru' => stripslashes($tmp['ru']),
                            'en' => stripslashes($tmp['en'])
                    );
                die(json_encode ($this->page_data));
            }
            else {
                die(json_encode (array(
                    'id'=>'',
                    'link'=>'',
                    'on'=>0,
                    'share'=>0,
                    'print'=>0,
                    'show'=>0,
                    'title'=>array('ru'=>'','en'=>''),
                    'content'=>array('ru'=>'','en'=>''),
                    'parent_id'=>0)));
            }
        }
        
        
        if(request::get('submit')) {
            $this->set_renderer('ajax');
            $this->disable_layout();
            
            $id= request::get_int('id');
            $action = request::get('act');
            $content = serialize(request::get('content'));
            $title = serialize(request::get('title'));
            $url = request::get('link');
            
            
            switch($action) {
                case 'save':
                    if(!$url) $this->json = die(json_encode (array('success'=>0,'reason'=>'Отсутствует адрес страницы')));
                    $max_weight = db::get_scalar('SELECT MAX(weight) FROM pages');
                    $ins_data = array(
                        'link'=>$url,
                        'title'=>$title,
                        'content'=>$content,
                        'created_ts'=>time(),
                        'on'=>  request::get_int('on'),
                        'show'=>  request::get_int('show'),
                        'print'=>  request::get_int('print'),
                        'share'=>  request::get_int('share'),
                        'parent_id'=>  request::get_int('parent'),
                        
                    );
                    if($id && ($this->page_data['link']==$url || ($this->page_data['link']!=$url && !pages_peer::instance()->get_by_url($url)))) {
                        pages_peer::instance()->update(array_merge(array('id'=>$id),$ins_data));
                        $this->json = array('success'=>1,'action'=>'upd');}
                    elseif(!$id && !pages_peer::instance()->get_by_url($url)) {
                            $ins_id = pages_peer::instance()->insert(array_merge(array('weight'=>($max_weight+1)),$ins_data));
                            $this->json = array('success'=>1,'id'=>$ins_id);}
                    else $this->json = array('success'=>0,'reason'=>'Не уникальный адрес страницы');
                    break;
                case 'change_property': 
                    $field = request::get_string('property');
                    $value = request::get('value');
                    if(!in_array($field, array('on','show','share','print')) || !in_array($value, array(1,0))) die(json_encode (array('success'=>0,'reason'=>'Input params error')));
                    db::exec('UPDATE pages SET "'.$field.'"='.$value.' WHERE id=:id',array('id'=>$id));
                    break;
                case 'delete': 
                    $item_data = pages_peer::instance()->get_item($id);
                    if($item_data) {
                        $children_list = pages_peer::instance()->get_list(array('parent_id'=>$id));
                        if(!empty($children_list))
                            foreach ($children_list as $child_id) 
                                db::exec("UPDATE pages SET parent_id=:pid WHERE id=:id",array('id'=>$child_id,'pid'=>$item_data['parent_id']));
                        pages_peer::instance()->delete_item($id);
                    }
                    break;
                case 'move': 
                    $dir = request::get_int('direct');
                    if(!in_array($dir, array(0,1))) die(json_encode (array('success'=>0,'reason'=>'Invalid input data')));
                    $item_data = db::get_row("SELECT * FROM pages WHERE id=:id",array('id'=>$id));
                    $w = db::get_scalar("SELECT ".($dir ? 'MAX' : 'MIN')."(weight) FROM pages WHERE parent_id=:pid AND weight".($dir ? '<' : '>').":w",array('pid'=>$item_data['parent_id'],'w'=>$item_data['weight']));
                    if($w) {
                        $change_element = db::get_row("SELECT * FROM pages WHERE weight=:cel",array('cel'=>$w));
                        if(!empty($change_element)) {
                            $tmp = $item_data['weight'];
                            $item_data['weight'] = $change_element['weight'];
                            $change_element['weight'] = $tmp;
                            pages_peer::instance()->update($item_data);
                            pages_peer::instance()->update($change_element);
                        }
                    }
                    break;
                default :
                    break;
            }
            
            
        }
    }
}

