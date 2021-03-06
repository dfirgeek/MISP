<?php

App::uses('AppController', 'Controller');

class TagsController extends AppController {
	public $components = array('Security' ,'RequestHandler');

	public $paginate = array(
			'limit' => 50,
			'order' => array(
					'Tag.name' => 'asc'
			),
			'contain' => array(
				'EventTag' => array(
					'fields' => array('event_id')
				),
				'FavouriteTag',
				'Organisation' => array(
					'fields' => array('id', 'name')
				)
			)
	);

	public $helpers = array('TextColour');

	public function index($favouritesOnly = false) {
		$this->loadModel('Event');
		$this->loadModel('Taxonomy');
		$taxonomies = $this->Taxonomy->listTaxonomies(array('full' => false, 'enabled' => true));
		$taxonomyNamespaces = array();
		if (!empty($taxonomies)) foreach ($taxonomies as $taxonomy) $taxonomyNamespaces[$taxonomy['namespace']] = $taxonomy;
		$taxonomyTags = array();
		$this->Event->recursive = -1;
		if ($favouritesOnly) {
			$tag_id_list = $this->Tag->FavouriteTag->find('list', array(
					'conditions' => array('FavouriteTag.user_id' => $this->Auth->user('id')),
					'fields' => array('FavouriteTag.tag_id')
			));
			if (empty($tag_id_list)) $tag_id_list = array(-1);
			$this->paginate['conditions']['AND']['Tag.id'] = $tag_id_list;
		}
		if ($this->_isRest()) {
			unset($this->paginate['limit']);
			$paginated = $this->Tag->find('all', $this->paginate);
		} else {
			$paginated = $this->paginate();
		}
		foreach ($paginated as $k => $tag) {
			if (empty($tag['EventTag'])) $paginated[$k]['Tag']['count'] = 0;
			else {
				$eventIDs = array();
				foreach ($tag['EventTag'] as $eventTag) {
					$eventIDs[] = $eventTag['event_id'];
				}
				$conditions = array('Event.id' => $eventIDs);
				if (!$this->_isSiteAdmin()) $conditions = array_merge(
					$conditions,
					array('OR' => array(
						array('AND' => array(
							array('Event.distribution >' => 0),
							array('Event.published =' => 1)
						)),
						array('Event.orgc_id' => $this->Auth->user('org_id'))
					)));
				$events = $this->Event->find('all', array(
					'fields' => array('Event.id', 'Event.distribution', 'Event.orgc_id'),
					'conditions' => $conditions
				));
				$paginated[$k]['Tag']['count'] = count($events);
			}
			unset($paginated[$k]['EventTag']);
			if (!empty($tag['FavouriteTag'])) {
				foreach ($tag['FavouriteTag'] as $ft) if ($ft['user_id'] == $this->Auth->user('id')) $paginated[$k]['Tag']['favourite'] = true;
				if (!isset($tag['Tag']['favourite'])) $paginated[$k]['Tag']['favourite'] = false;
			} else $paginated[$k]['Tag']['favourite'] = false;
			unset($paginated[$k]['FavouriteTag']);
			if (!empty($taxonomyNamespaces)) {
				$taxonomyNamespaceArrayKeys = array_keys($taxonomyNamespaces);
				foreach ($taxonomyNamespaceArrayKeys as $tns) {
					if (substr(strtoupper($tag['Tag']['name']), 0, strlen($tns)) === strtoupper($tns)) {
						$paginated[$k]['Tag']['Taxonomy'] = $taxonomyNamespaces[$tns];
						if (!isset($taxonomyTags[$tns])) $taxonomyTags[$tns] = $this->Taxonomy->getTaxonomyTags($taxonomyNamespaces[$tns]['id'], true);
						$paginated[$k]['Tag']['Taxonomy']['expanded'] = isset($taxonomyTags[$tns][strtoupper($tag['Tag']['name'])]) ? $taxonomyTags[$tns][strtoupper($tag['Tag']['name'])] : $tag['Tag']['name'];
					}
				}
			}
		}
		if ($this->_isRest()) {
			foreach ($paginated as $key => $tag) {
				$paginated[$key] = $tag['Tag'];
			}
			$this->set('Tag', $paginated);
			$this->set('_serialize', array('Tag'));
		} else {
			$this->set('list', $paginated);
			$this->set('favouritesOnly', $favouritesOnly);
		}
		// send perm_tagger to view for action buttons
	}

	public function add() {
		if (!$this->_isSiteAdmin() && !$this->userRole['perm_tag_editor']) throw new NotFoundException('You don\'t have permission to do that.');
		if ($this->request->is('post')) {
			if (isset($this->request->data['Tag']['request'])) $this->request->data['Tag'] = $this->request->data['Tag']['request'];
			if (!isset($this->request->data['Tag']['colour'])) $this->request->data['Tag']['colour'] = $this->Tag->random_color();
			if (isset($this->request->data['Tag']['id'])) unset($this->request->data['Tag']['id']);
			if ($this->Tag->save($this->request->data)) {
				if ($this->_isRest()) $this->redirect(array('action' => 'view', $this->Tag->id));
				$this->Session->setFlash('The tag has been saved.');
				$this->redirect(array('action' => 'index'));
			} else {
				if ($this->_isRest()) {
					$error_message = '';
					foreach ($this->Tag->validationErrors as $k => $v) $error_message .= '[' . $k . ']: ' . $v[0];
					throw new MethodNotAllowedException('Could not add the Tag. ' . $error_message);
				} else {
					$this->Session->setFlash('The tag could not be saved. Please, try again.');
				}
			}
		}
		$this->loadModel('Organisation');
		$temp = $this->Organisation->find('all', array(
			'conditions' => array('local' => 1),
			'fields' => array('id', 'name'),
			'recursive' => -1
		));
		$orgs = array(0 => 'Unrestricted');
		if (!empty($temp)) {
			foreach ($temp as $org) {
				$orgs[$org['Organisation']['id']] = $org['Organisation']['name'];
			}
		}
		$this->set('orgs', $orgs);
	}

	public function quickAdd() {
		if ((!$this->_isSiteAdmin() && !$this->userRole['perm_tag_editor']) || !$this->request->is('post')) throw new NotFoundException('You don\'t have permission to do that.');
		if (isset($this->request->data['Tag']['request'])) $this->request->data['Tag'] = $this->request->data['Tag']['request'];
		if ($this->Tag->quickAdd($this->request->data['Tag']['name'])) {
			$this->Session->setFlash('The tag has been saved.');
		} else {
			$this->Session->setFlash('The tag could not be saved. Please, try again.');
		}
		$this->redirect($this->referer());
	}

	public function edit($id) {
		if (!$this->_isSiteAdmin() && !$this->userRole['perm_tag_editor']) {
			throw new NotFoundException('You don\'t have permission to do that.');
		}
		$this->Tag->id = $id;
		if (!$this->Tag->exists()) {
			throw new NotFoundException('Invalid tag');
		}
		if ($this->request->is('post') || $this->request->is('put')) {
			$this->request->data['Tag']['id'] = $id;
			if (isset($this->request->data['Tag']['request'])) $this->request->data['Tag'] = $this->request->data['Tag']['request'];

			if ($this->Tag->save($this->request->data)) {
				if ($this->_isRest()) $this->redirect(array('action' => 'view', $id));
				$this->Session->setFlash('The Tag has been edited');
				$this->redirect(array('action' => 'index'));
			} else {
				if ($this->_isRest()) {
					$error_message = '';
					foreach ($this->Tag->validationErrors as $k => $v) $error_message .= '[' . $k . ']: ' . $v[0];
					throw new MethodNotAllowedException('Could not add the Tag. ' . $error_message);
				}
				$this->Session->setFlash('The Tag could not be saved. Please, try again.');
			}
		}
		$this->loadModel('Organisation');
		$temp = $this->Organisation->find('all', array(
			'conditions' => array('local' => 1),
			'fields' => array('id', 'name'),
			'recursive' => -1
		));
		$orgs = array(0 => 'Unrestricted');
		if (!empty($temp)) {
			foreach ($temp as $org) {
				$orgs[$org['Organisation']['id']] = $org['Organisation']['name'];
			}
		}
		$this->set('orgs', $orgs);
		$this->request->data = $this->Tag->read(null, $id);
	}

	public function delete($id) {
		if (!$this->_isSiteAdmin() && !$this->userRole['perm_tag_editor']) {
			throw new NotFoundException('You don\'t have permission to do that.');
		}
		if (!$this->request->is('post')) {
			throw new MethodNotAllowedException();
		}
		$this->Tag->id = $id;
		if (!$this->Tag->exists()) {
			throw new NotFoundException('Invalid tag');
		}
		if ($this->Tag->delete()) {
			if ($this->_isRest()) {
				$this->set('name', 'Tag deleted.');
				$this->set('message', 'Tag deleted.');
				$this->set('url', '/tags/delete/' . $id);
				$this->set('_serialize', array('name', 'message', 'url'));
			}
			$this->Session->setFlash(__('Tag deleted'));
		} else {
			if ($this->_isRest()) throw new MethodNotAllowedException('Could not delete the tag, or tag doesn\'t exist.');
			$this->Session->setFlash(__('Tag was not deleted'));
		}
		if (!$this->_isRest()) $this->redirect(array('action' => 'index'));
	}

	public function view($id) {
		if ($this->_isRest()) {
			$tag = $this->Tag->find('first', array(
					'conditions' => array('id' => $id),
					'recursive' => -1,
					'contain' => array('EventTag' => array('fields' => 'event_id'))
			));
			if (empty($tag)) throw new MethodNotAllowedException('Invalid Tag');
			if (empty($tag['EventTag'])) $tag['Tag']['count'] = 0;
			else {
				$eventIDs = array();
				foreach ($tag['EventTag'] as $eventTag) {
					$eventIDs[] = $eventTag['event_id'];
				}
				$conditions = array('Event.id' => $eventIDs);
				if (!$this->_isSiteAdmin()) $conditions = array_merge(
						$conditions,
						array('OR' => array(
								array('AND' => array(
										array('Event.distribution >' => 0),
										array('Event.published =' => 1)
								)),
								array('Event.orgc_id' => $this->Auth->user('org_id'))
						)));
				$events = $this->Tag->EventTag->Event->find('all', array(
						'fields' => array('Event.id', 'Event.distribution', 'Event.orgc_id'),
						'conditions' => $conditions
				));
				$tag['Tag']['count'] = count($events);
			}
			unset($tag['EventTag']);
			$this->set('Tag', $tag['Tag']);
			$this->set('_serialize', 'Tag');
		} else throw new MethodNotAllowedException('This action is only for REST users.');

	}

	public function showEventTag($id) {
		$this->loadModel('EventTag');
		if (!$this->EventTag->Event->checkIfAuthorised($this->Auth->user(), $id)) {
			throw new MethodNotAllowedException('Invalid event.');
		}
		$this->loadModel('GalaxyCluster');
		$cluster_names = $this->GalaxyCluster->find('list', array('fields' => array('GalaxyCluster.tag_name'), 'group' => array('GalaxyCluster.tag_name')));
		$this->helpers[] = 'TextColour';
		$tags = $this->EventTag->find('all', array(
				'conditions' => array(
						'event_id' => $id,
						'Tag.name !=' => $cluster_names
				),
				'contain' => array('Tag'),
				'fields' => array('Tag.id', 'Tag.colour', 'Tag.name'),
		));
		$this->set('tags', $tags);
		$event = $this->Tag->EventTag->Event->find('first', array(
				'recursive' => -1,
				'fields' => array('Event.id', 'Event.orgc_id', 'Event.org_id', 'Event.user_id'),
				'conditions' => array('Event.id' => $id)
		));
		$this->set('event', $event);
		$this->layout = 'ajax';
		$this->render('/Events/ajax/ajaxTags');
	}

	public function viewTag($id) {
		$tag = $this->Tag->find('first', array(
				'conditions' => array(
						'id' => $id
				),
				'recursive' => -1,
		));
		$this->layout = null;
		$this->set('tag', $tag);
		$this->set('id', $id);
		$this->render('ajax/view_tag');
	}


	public function selectTaxonomy($event_id) {
		if (!$this->_isSiteAdmin() && !$this->userRole['perm_tagger']) throw new NotFoundException('You don\'t have permission to do that.');
		$favourites = $this->Tag->FavouriteTag->find('count', array('conditions' => array('FavouriteTag.user_id' => $this->Auth->user('id'))));
		$this->loadModel('Taxonomy');
		$options = $this->Taxonomy->find('list', array('conditions' => array('enabled' => true), 'fields' => array('namespace'), 'order' => array('Taxonomy.namespace ASC')));
		foreach ($options as $k => $option) {
			$tags = $this->Taxonomy->getTaxonomyTags($k, false, true);
			if (empty($tags)) unset($options[$k]);
		}
		$this->set('event_id', $event_id);
		$this->set('options', $options);
		$this->set('favourites', $favourites);
		$this->render('ajax/taxonomy_choice');
	}

	public function selectTag($event_id, $taxonomy_id) {
		if (!$this->_isSiteAdmin() && !$this->userRole['perm_tagger']) throw new NotFoundException('You don\'t have permission to do that.');
		$this->loadModel('Taxonomy');
		$expanded = array();
		if ($taxonomy_id === '0') {
			$options = $this->Taxonomy->getAllTaxonomyTags(true);
			$expanded = $options;
		} else if ($taxonomy_id === 'favourites') {
			$conditions = array('FavouriteTag.user_id' => $this->Auth->user('id'));
			$tags = $this->Tag->FavouriteTag->find('all', array(
				'conditions' => $conditions,
				'recursive' => -1,
				'contain' => array('Tag.name')
			));
			foreach ($tags as $tag) {
				$options[$tag['FavouriteTag']['tag_id']] = $tag['Tag']['name'];
				$expanded = $options;
			}
		} else if ($taxonomy_id === 'all') {
			$conditions = array('Tag.org_id' => array(0, $this->Auth->user('org_id')));
			if (Configure::read('MISP.incoming_tags_disabled_by_default')) {
				$conditions['Tag.hide_tag'] = 0;
			}
			$options = $this->Tag->find('list', array('fields' => array('Tag.name'), 'conditions' => $conditions));
			$expanded = $options;
		} else {
			$taxonomies = $this->Taxonomy->getTaxonomy($taxonomy_id);
			$options = array();
			foreach ($taxonomies['entries'] as $entry) {
				if (!empty($entry['existing_tag']['Tag'])) {
					$options[$entry['existing_tag']['Tag']['id']] = $entry['existing_tag']['Tag']['name'];
					$expanded[$entry['existing_tag']['Tag']['id']] = $entry['expanded'];
				}
			}
		}
		// Unset all tags that this user cannot use for tagging, determined by the org restriction on tags
		if (!$this->_isSiteAdmin()) {
			$banned_tags = $this->Tag->find('list', array(
					'conditions' => array(
							'NOT' => array(
									'Tag.org_id' => array(
											0,
											$this->Auth->user('org_id')
									)
							)
					),
					'fields' => array('Tag.id')
			));
			foreach ($banned_tags as $banned_tag) {
				unset($options[$banned_tag]);
				unset($expanded[$banned_tag]);
			}
		}
		foreach ($options as $k => $v) {
			if (substr($v, 0, strlen('misp-galaxy:')) === 'misp-galaxy:') {
				unset($options[$k]);
			}
		}
		$this->set('event_id', $event_id);
		$this->set('options', $options);
		$this->set('expanded', $expanded);
		$this->set('custom', $taxonomy_id == 0 ? true : false);
		$this->render('ajax/select_tag');
	}

	public function tagStatistics($percentage = false, $keysort = false) {
		$result = $this->Tag->EventTag->find('all', array(
				'recursive' => -1,
				'fields' => array('count(EventTag.id) as count', 'tag_id'),
				'contain' => array('Tag' => array('fields' => array('Tag.name'))),
				'group' => array('tag_id')
		));
		$tags = array();
		$taxonomies = array();
		$totalCount = 0;
		$this->loadModel('Taxonomy');
		$temp = $this->Taxonomy->listTaxonomies(array('enabled' => true));
		foreach ($temp as $t) {
			if ($t['enabled']) $taxonomies[$t['namespace']] = 0;
		}
		foreach ($result as $r) {
			if ($r['Tag']['name'] == null) continue;
			$tags[$r['Tag']['name']] = $r[0]['count'];
			$totalCount += $r[0]['count'];
			foreach ($taxonomies as $taxonomy => $count) {
				if (substr(strtolower($r['Tag']['name']), 0, strlen($taxonomy)) === strtolower($taxonomy)) $taxonomies[$taxonomy] += $r[0]['count'];
			}
		}
		if ($keysort === 'true') {
			ksort($tags, SORT_NATURAL | SORT_FLAG_CASE);
			ksort($taxonomies, SORT_NATURAL | SORT_FLAG_CASE);
		} else {
			arsort($tags);
			arsort($taxonomies);
		}
		if ($percentage === 'true') {
			foreach ($tags as $tag => $count) {
				$tags[$tag] = round(100 * $count / $totalCount, 3) . '%';
			}
			foreach ($taxonomies as $taxonomy => $count) {
				$taxonomies[$taxonomy] = round(100 * $count / $totalCount, 3) . '%';
			}
		}
		$results = array('tags' => $tags, 'taxonomies' => $taxonomies);
		$this->autoRender = false;
		$this->layout = false;
		$this->set('data', $results);
		$this->set('flags', JSON_PRETTY_PRINT);
		$this->response->type('json');
		$this->render('/Servers/json/simple');
	}
}
