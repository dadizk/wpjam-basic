jQuery(function($){
	$.fn.extend({
		wpjam_scroll: function(){
			let el_top	= $(this).offset().top;
			let el_btm	= el_top + $(this).height();

			if((el_top > $(window).scrollTop() + $(window).height() - 100) || (el_btm  < $(window).scrollTop() + 100)){
				$('html, body').animate({scrollTop: el_top - 100}, 400);
			}
		},

		wpjam_bg_color: function(bg_color){
			if(!bg_color){
				bg_color	= $(this).prevAll().length % 2 ? '#ffffeecc' : '#ffffddcc';
			}

			return $(this).css('background-color', bg_color);
		}, 

		wpjam_list_table_sortable: function(){
			let args = $(this).is('tbody') ? {items: wpjam_list_table.sortable.items, axis: 'y'} : {items: '> div.item'};

			return $(this).sortable($.extend(args, {
				cursor:			'move',
				handle:			'.list-table-move-action',
				containment:	$(this).parent().parent(),

				create: function(e, ui){
					$(this).find(args.items).addClass('ui-sortable-item');
				},

				start: function(e, ui){
					ui.placeholder.css({
						'visibility'		: 'visible',
						'background-color'	: '#eeffffcc',
						'width'				: ui.item.width()+'px',
						'height'			: ui.item.height()+'px'
					});
				},

				update:	function(e, ui){
					ui.item.css('background-color', '#eeffeecc');

					let handle	= ui.item.find('.ui-sortable-handle');
					let args	= {
						action_type:	'direct',
						list_action:	handle.data('action'),
						data:			handle.data('data'),
						id:				handle.data('id'),
						_ajax_nonce: 	handle.data('nonce')
					};

					let data	= {};

					$.each(['prev', 'next'], (i, key) => {
						let handle	= ui.item[key]().find('.ui-sortable-handle');

						if(handle.length){
							if($(this).is('tbody')){
								data[key]	= handle.data('id');
							}else{
								data[key]	= handle.data('i');

								if(!((key == 'next') ^ (ui.item.data('i') >= data[key]))){
									args.pos	= data[key]
								}
							}
						}
					});

					args.data	+= '&type=drag&'+$.param(data)+'&'+$(this).sortable('serialize');

					$(this).sortable('disable');

					$.when($.wpjam_list_table_action(args)).then(() => {
						$(this).sortable('enable').find(args.items).addClass('ui-sortable-item');
					});
				}
			}));
		}
	});

	$.extend({
		wpjam_post: function(args, callback){
			$.wpjam_append_page_setting(args);

			if(args.action_type){
				if(args.action_type == 'submit'){
					if(document.activeElement.tagName != 'BODY'){
						window.submit_button	= document.activeElement;

						$(window.submit_button).prop('disabled', true).after('<span class="spinner is-active"></span>');
					}
				}else{
					if(!window.loading_disabled){
						$('<div id="TB_load"><img src="'+imgLoader.src+'" width="208" /></div>').appendTo('body').show();
					}
				}
			}

			return $.post(ajaxurl, args, (data, status) => {
				if(args.action_type){
					data	= (typeof data == 'object') ? data : JSON.parse(data);

					if(args.action_type == 'submit'){
						if(window.submit_button){
							$(window.submit_button).prop('disabled', false).next('.spinner').remove();
						}
					}else{
						$('#TB_load').remove();
					}
				}

				callback(data, status);
			});
		},

		wpjam_filter: function(data){
			for(let prop in data){
				if(data[prop] == null){
					delete data[prop];
				}
			}

			return data;
		},

		wpjam_notice: function(notice, type){
			notice	= $('<div id="wpjam_notice" class="notice notice-'+type+' is-dismissible inline" style="opacity:0;"><p><strong>'+notice+'</strong></p></div>')
			.slideDown(200, function(){
				$(this).fadeTo(200, 1, function(){
					$('<button type="button" class="notice-dismiss"><span class="screen-reader-text">忽略此提示。</span></button>').on('click.wp-dismiss-notice', function(e){
						e.preventDefault();
						$(this).parent().fadeTo(200, 0, function(){
							$(this).slideUp(200, function(){
								$(this).remove();
							});
						});
					}).appendTo($(this));
				});
			});

			if($('body').hasClass('modal-open')){
				$('#TB_ajaxContent').find('.notice').remove().end().animate({scrollTop: 0}, 300).prepend(notice);
			}else{
				$('div.wrap').find('#wpjam_notice').remove().end().find('.wp-header-end').before(notice).wpjam_scroll();
			}
		},

		wpjam_modal: function(html, title, width, modal_id){
			modal_id			= modal_id || 'tb_modal';
			window.modal_width	= width ? width + 30 : 0;

			if(html instanceof jQuery){
				width	= width || html.data('width');
				title	= title || html.data('title') || ' ';
				html	= html.html();
			}

			if(modal_id == 'tb_modal'){
				if($('#TB_window').length){
					$('#TB_ajaxWindowTitle').html(title);
					$('#TB_ajaxContent').html(html);

					tb_position();
				}else{
					if(!$('body #tb_modal').length){
						$('body').append('<div id="tb_modal"></div>');
					
						[$.wpjam_position, window.tb_position]	= [window.tb_position, $.wpjam_position];

						if(window.send_to_editor && !$.wpjam_send_to_editor){
							[$.wpjam_send_to_editor, window.send_to_editor]	= [window.send_to_editor, function(html){
								[$.wpjam_tb_remove, window.tb_remove]	= [window.tb_remove, null];
								
								$.wpjam_send_to_editor(html);

								window.tb_remove	= $.wpjam_tb_remove;
							}];
						}
					}

					$('#tb_modal').html(html);

					tb_show(title, '#TB_inline?inlineId=tb_modal&width='+(width || 720));
				}
			}else{
				if(!$('body .modal').length){
					$('body').append('<div class="modal"><div class="modal-title">'+title+'</div><div class="modal-content">'+html+'</div></div><div class="modal-overlay"></div>').addClass('modal-open');

					$('<div class="modal-close"></div>').on('click', function(){
						$('body').removeClass('modal-open');

						$(this).parent().fadeOut(300, function(){
							$(this).remove();
							$('.modal-overlay').remove();
						});

						return false;
					}).prependTo('div.modal');

					$(window).on('resize', $.wpjam_position);
				}

				$.wpjam_position();
			}
		},

		wpjam_position: function(){
			let style	= {maxHeight: Math.min(900, $(window).height()-120)};
			let width	= $(window).width()-20;

			if(window.modal_width){
				width	= Math.min(window.modal_width, width);
			}

			if($('#TB_window').length){
				$('#TB_window').addClass('abscenter');

				if(width <= TB_WIDTH){
					style.width	= width - 50;
				}else{
					style.maxWidth	= TB_WIDTH - 50;
				}

				$('#TB_ajaxContent').removeAttr('style').css(style);

				$('#TB_overlay').off('click');
			}else if($('.modal').length){
				if(width < 720){
					style.width		= width - 50;
				}else{
					style.maxWidth	= 690;
				}

				$('.modal').css(style);
			}
		},

		wpjam_list_table_action: function(args){
			let list_action	= args.list_action;
			let action_type	= args.action_type = args.action_type || args.list_action_type;

			args.action	= 'wpjam-list-table-action';

			if($('tbody th.check-column').length){
				window.loading_disabled	= action_type == 'direct';

				$.each(((args.bulk && args.bulk != 2) ? args.ids : (args.id ? [args.id] : [])), (i, id) => $.wpjam_list_table_item(id).find('.check-column input').after('<span class="spinner is-active"></span>').hide());
			}

			return $.wpjam_post(args, (response) => {
				$('.wp-list-table .spinner.is-active').prev('input').show().next('.spinner').remove();

				if(args.bulk){
					$('thead td.check-column input, tfoot td.check-column input').prop('checked', false);
				}else if(args.id){
					$('.wp-list-table > tbody tr').not('#'+$.wpjam_list_table_item(args.id).attr('id')).css('background-color', '');
				}

				if(response.errcode != 0){
					if(action_type == 'direct'){
						alert(response.errmsg);
					}else{
						$.wpjam_notice(response.errmsg, 'error');
					}
				}else{
					if(response.setting){
						wpjam_page_setting.list_table	= wpjam_list_table	= $.extend(wpjam_list_table, response.setting);
					}

					let $subsubsub		= $('body div.list-table .subsubsub');
					let current_view	= $subsubsub.find('li a.current').parent().attr('class');

					if(action_type == 'query_items' || action_type == 'left'){
						if(action_type == 'left'){
							$('div#col-left div').html(response.left);
						}

						$.wpjam_list_table_list_response(response);

						current_view	= '';

						$('html').scrollTop(0);
					}else if(action_type == 'form'){
						$.wpjam_modal(response.form, response.page_title, response.width);
					}else{
						$.wpjam_list_table_response(response, args);
					}

					if(response.views){
						let $views	= $(response.views);

						if(current_view){
							$views.find('li a').removeClass('current').end().find('li.'+current_view+' a').addClass('current');
						}

						$subsubsub.after($views).remove();
					}

					$.wpjam_push_state();

					response.list_action	= list_action;
					response.action_type	= response.list_action_type	= action_type;

					$('body').trigger('list_table_action_success', response);
				}
			});
		},

		wpjam_list_table_list_response:function(response){
			if(response.table){
				$('body div.list-table').find('input[name="_wpnonce"], input[name="_wp_http_referer"], table, div.tablenav.top, div.tablenav.bottom').remove().end().find('form').append(response.table);
			}else{
				$('body div.list-table').html(response.data);
			}
		},

		wpjam_list_table_response: function(response, args){
			if(response.type == 'items' && response.items){
				$.each(response.items, (i, item) => $.wpjam_list_table_response(item, args));
			}else if(response.type == 'redirect'){
				$.wpjam_response_redirect(response);
			}else if(response.type == 'append'){
				if($('body').hasClass('modal-open')){
					$.wpjam_response_append(response);
				}else{
					$.wpjam_modal(response.data, response.page_title, response.width);
				}
			}else{
				if($('body').hasClass('modal-open')){
					if(response.dismiss){
						tb_remove();
					}else{
						$.wpjam_modal(response.form, response.page_title, response.width);
					}
				}

				if(response.errmsg){
					$.wpjam_notice(response.errmsg, 'success');
				}

				let $item	= $.wpjam_list_table_item(args.id);

				if(response.type == 'form'){
					//
				}else if(response.type == 'list'){
					if(response.list_action == 'delete'){
						$.when($.wpjam_list_table_delete_item(response)).then(() => setTimeout(() => $.wpjam_list_table_list_response(response), 300));
					}else{
						$.wpjam_list_table_list_response(response);

						if(response.bulk){
							$.each(response.ids, (index, id) => $.wpjam_list_table_update_item({id: id}));
						}else if(response.id){
							$.wpjam_list_table_update_item({id: response.id});
						}
					}
				}else if(response.type == 'add' || response.type == 'duplicate'){
					$.wpjam_list_table_create_item(response);
				}else if(response.type == 'delete'){
					$.wpjam_list_table_delete_item(response);
				}else if(response.type == 'up' || response.type == 'down'){
					if(response.type == 'up'){
						$.wpjam_list_table_item(args.next).insertAfter($item);
					}else{
						$.wpjam_list_table_item(args.prev).after($item);
					}

					$.wpjam_list_table_update_item(response, '#eeffffcc');
				}else if(response.type == 'move'){
					$.wpjam_list_table_update_item(response, '#eeffeecc');
				}else if(response.type == 'move_item'){
					$.wpjam_list_table_update_item(response, false).find('.items [data-i="'+args.pos+'"]').css('background-color', '#eeffeecc');
				}else if(response.type == 'add_item'){
					$.wpjam_list_table_update_item(response, false).find('.items .item:not(.add-item)').last().css('background-color', '#ffffeecc');
				}else if(response.type == 'edit_item'){
					$.wpjam_list_table_update_item(response, false).find('.items [data-i="'+(new URLSearchParams(args.defaults)).get('i')+'"]').css('background-color', '#ffffeecc');
				}else if(response.type == 'del_item'){
					$item.find('.items [data-i="'+(new URLSearchParams(args.data)).get('i')+'"]').css('background-color', '#ff0000cc').fadeOut(400, function(){ $.wpjam_list_table_update_item(response, false);});
				}else{
					$.wpjam_list_table_update_item(response);
				}

				if(response.next){
					wpjam_params.list_action	= response.next;

					if(response.next != 'add' && response.id){
						wpjam_params.id	= response.id;
					}

					if(args.data && response.type == 'form'){
						wpjam_params.data	= args.data;
					}
				}
			}
		},

		wpjam_list_table_bulk_action: function(ids, args){
			args.id	= ids.shift();

			$.wpjam_list_table_item(args.id).wpjam_scroll();

			$.when($.wpjam_list_table_action(args)).then(() => {
				if(ids.length){
					$.wpjam_list_table_bulk_action(ids, args);
				}
			});
		},

		wpjam_list_table_create_item: function(response){
			if(response.data){
				if(response.layout == 'calendar'){
					$.wpjam_list_table_update_date(response);
				}else{
					if(response.bulk){
						$.each(response.data, (id, item) => $.wpjam_list_table_create_item({id: id, data: item}));
					}else{
						if(response.after){
							$.wpjam_list_table_item(response.after).after(response.data);
						}else if(response.before){
							$.wpjam_list_table_item(response.before).before(response.data);
						}else if(response.last){
							$('.wp-list-table > tbody tr').last().after(response.data);
						}else{
							$('.wp-list-table > tbody tr').first().before(response.data);
						}

						$.wpjam_list_table_item(response.id).hide().wpjam_bg_color().fadeIn(400).wpjam_scroll();
					}

					$('.no-items').remove();
				}
			}
		},

		wpjam_list_table_update_item: function(response, bg_color){
			if(response.layout == 'calendar'){
				$.wpjam_list_table_update_date(response);
			}else{
				if(response.bulk){
					$.each(response.data, (id, item) => $.wpjam_list_table_update_item({id: id, data: item}));
				}else{
					if(response.id){
						let $item	= $.wpjam_list_table_item(response.id);

						if(response.data){
							$item	= $(response.data);

							$.wpjam_list_table_item(response.id).last().before($item).end().remove();
						}

						if(bg_color !== false){
							$item.hide().wpjam_bg_color(bg_color).fadeIn(1000);
						}

						return $item;
					}
				}
			}
		},

		wpjam_list_table_delete_item: function(response){
			if(response.layout == 'calendar'){
				$.wpjam_list_table_update_date(response);
			}else{
				if(response.bulk){
					$.each(response.ids, (i, id) => $.wpjam_list_table_delete_item({id: id}));
				}else{
					$.wpjam_list_table_item(response.id).css('background-color', '#ff0000cc').fadeOut(400, () => $(this).remove());
				}
			}
		},

		wpjam_list_table_update_date(response){
			$.each(response.data, (date, item) => $('td#date_'+date).html(item).wpjam_bg_color());
		},

		wpjam_list_table_item: function(id){
			id	= typeof(id) == "string" ? id.replace(/(:|\.|\[|\]|,|=|@)/g, "\\$1") : id;

			if($('.tr-'+id).length){
				return $('.tr-'+id);
			}

			let prefix	= '#post';

			if($('.wp-list-table tbody').data('wp-lists')){
				prefix	= '#'+$('.wp-list-table tbody').data('wp-lists').split(':')[1];
				// prefix	= '#'+$('#the-list').data('wp-lists').split(':')[1];
			}

			return $(prefix+'-'+id);
		},

		wpjam_list_table_query_items: function(type){
			if(wpjam_list_table.left_key){
				let left_key	= wpjam_list_table.left_key;

				if(type == 'left'){
					delete wpjam_params[left_key];
				}else{
					wpjam_params[left_key]	= $('tr.left-current').data('id');
				}
			}

			if(wpjam_params.hasOwnProperty('id')){
				delete wpjam_params.id;
			}

			wpjam_params	= $.wpjam_filter(wpjam_params);

			$.wpjam_list_table_action({
				action_type:	type || 'query_items',
				data:			$.param(wpjam_params)
			});

			return false;
		},

		wpjam_list_table_loaded: function(){
			if($(window).width() > 782){
				if($('p.search-box').length){
					$('ul.subsubsub').css('max-width', 'calc(100% - '+($('p.search-box').width() + 5)+'px)');
				}else{
					if($('.tablenav.top').find('div.alignleft').length == 0){
						$('.tablenav.top').css({clear:'none'});
					}
				}
			}

			if($('.wrap .list-table').length == 0){
				$('ul.subsubsub, form#posts-filter').wrapAll('<div class="list-table" />');
			}

			let $list_table	= $('table.wp-list-table');

			$('input[name=_wp_http_referer]').val($.wpjam_admin_url());

			$.wpjam_push_state();

			if(wpjam_list_table.sticky_columns.length){
				if($('div.wrap .sticky-columns-list-table-wrap').length){
					$list_table.appendTo($('div.wrap .sticky-columns-list-table-wrap'));
				}else{
					$list_table.wrap('<div class="sticky-columns-list-table-wrap" />');
				}

				let width	= $list_table.find('.check-column').css('left', 0).width()+3;

				$.each(wpjam_list_table.sticky_columns, (index, column) => {
					width	+= $list_table.find('.column-'+column).css('left', width).width()+20;
				});
			}

			wpjam_list_table.loaded	= true;

			if(wpjam_params.id && !wpjam_params.list_action && !wpjam_params.action){
				if(!$.wpjam_list_table_item(wpjam_params.id).length){
					$.wpjam_list_table_action({action_type:'query_item', id:wpjam_params.id });
				}else{
					$.wpjam_list_table_update_item({id:wpjam_params.id});
				}

				delete wpjam_params.query_id;
			}

			if(wpjam_list_table.sortable){
				$list_table.find('> tbody.ui-sortable').sortable('destroy').end().find('> tbody').wpjam_list_table_sortable();
			}

			$list_table.find('> tbody .items.sortable.ui-sortable').sortable('destroy').end().find('> tbody .items.sortable').wpjam_list_table_sortable();

			if($.inArray(screen_base, ['edit', 'upload', 'edit-tags']) != -1 && wpjam_list_table.ajax){
				let page	= $('#adminmenu a.current').attr('href');

				$('body .subsubsub a[href^="'+page+'"], body tbody#the-list a[href^="'+page+'"]').each(function(){
					if(['list-table-no-ajax', 'list-table-filter'].some((name) => $(this).hasClass(name))){
						return;
					}

					let params	= Object.fromEntries((new URL($(this).prop('href'))).searchParams);

					if(params.page){
						$(this).addClass('list-table-no-ajax');
					}else{
						$(this).addClass('list-table-filter').data('filter', params);
					}
				});
			}
		},

		wpjam_response_append: function(response){
			let wrap	= $('body').hasClass('modal-open') ? '#TB_ajaxContent' : 'div.wrap';

			if(!$(wrap+' .response').length){
				$(wrap).append('<div class="card response hidden"></div>');
			}

			$(wrap+' .response').html(response.data).fadeIn(400);

			if($('body').hasClass('modal-open')){
				$('#TB_ajaxContent').scrollTop($('#TB_ajaxContent form').prop('scrollHeight'));
			}
		},

		wpjam_response_redirect: function(response){
			if(response.url){
				window.location.href	= response.url;
			}else{
				window.location.reload();
			}
		},

		wpjam_page_action: function (args){
			let action_type	= args.action_type = args.action_type || args.page_action_type || 'form';
			let page_action	= args.page_action;

			args.action	= 'wpjam-page-action';

			$.wpjam_post(args, function(response){
				if(response.errcode != 0){
					if(action_type == 'submit'){
						$.wpjam_notice(args.page_title+'失败：'+response.errmsg, 'error');
					}else{
						alert(response.errmsg);
					}
				}else{
					if(action_type == 'submit'){
						if(response.type == 'append'){
							$.wpjam_response_append(response);
						}else if(response.type == 'redirect'){
							$.wpjam_response_redirect(response);
						}else{
							if($('#wpjam_form').length){
								if(response.form){
									$('#wpjam_form').html(response.form);
								}
							}

							let notice_type	= response.notice_type || 'success';
							let notice_msg	= response.errmsg || args.page_title+'成功';

							$.wpjam_notice(notice_msg, notice_type);
						}

						if(response.done == 0){
							setTimeout(function(){
								args.data	= response.args;
								$.wpjam_page_action(args);
							}, 400);
						}
					}else if(action_type == 'form'){
						let response_form	= response.form || response.data;

						if(!response_form){
							alert('服务端未返回表单数据');
						}

						let callback	= args.callback;

						if(callback){
							callback.call(null, response);
						}else{
							$.wpjam_modal(response_form, response.page_title, response.width, response.modal_id);
						}
					}else{
						if(response.type == 'redirect'){
							$.wpjam_response_redirect(response);
						}else{
							if(response.errmsg){
								$.wpjam_notice(response.errmsg, 'success');
							}
						}
					}

					if(action_type != 'form' || response.modal_id == 'tb_modal'){
						$.wpjam_push_state();
					}

					response.page_action	= page_action;
					response.action_type	= response.page_action_type	= action_type;

					$('body').trigger('page_action_success', response);
				}
			});

			return false;
		},

		wpjam_option_action: function(args){
			args.action			= 'wpjam-option-action';
			args.action_type	= 'submit';

			$.wpjam_post(args, function(response){
				if(response.errcode != 0){
					let notice_msg	= args.option_action == 'reset' ? '重置' : '保存';

					$.wpjam_notice(notice_msg+'失败：'+response.errmsg, 'error');
				}else{
					$('body').trigger('option_action_success', response);

					if(response.type == 'reset' || response.type == 'redirect'){
						$('<form>').prop('method', 'POST').prop('action', window.location.href)
						.append($('<input>').prop('type', 'hidden').prop('name', 'response_type').prop('value', response.type))
						.appendTo(document.body)
						.submit();
					}else{
						$.wpjam_notice(response.errmsg, 'success');
					}
				}
			});

			return false;
		},

		wpjam_append_page_setting: function(args){
			args.screen_id	= wpjam_page_setting.screen_id;

			if(wpjam_page_setting.plugin_page){
				args.plugin_page	= wpjam_page_setting.plugin_page;
				args.current_tab	= wpjam_page_setting.current_tab;
			}

			if(wpjam_page_setting.post_type){
				args.post_type	= wpjam_page_setting.post_type;
			}

			if(wpjam_page_setting.taxonomy){
				args.taxonomy	= wpjam_page_setting.taxonomy;
			}

			if(wpjam_page_setting.query_data){
				let query_data	= wpjam_page_setting.query_data;

				if(args.data && typeof(args.data) != 'undefined'){
					$.each(args.data.split('&'), function(){
						let query	= this.split('=');

						if(query_data.hasOwnProperty(query[0])){
							query_data[query[0]]	= query[1];
						}
					});

					args.data	= $.param(query_data)+'&'+args.data;
				}else{
					args.data	= $.param(query_data);
				}
			}

			if(wpjam_list_table && wpjam_list_table.left_key && args.action_type != 'left'){
				let left_query	= wpjam_list_table.left_key+'='+$('tr.left-current').data('id');

				if(args.data && typeof(args.data) != 'undefined'){
					args.data	= args.data+'&'+left_query;
				}else{
					args.data	= left_query;
				}
			}

			return args;
		},

		wpjam_admin_url: function(){
			let admin_url	= wpjam_page_setting.admin_url || $('#adminmenu a.current').prop('href');

			let parts	= admin_url.split('?');
			let params	= new URLSearchParams(parts[1]);
			let query	= $.extend({}, wpjam_params);

			if(wpjam_page_setting.query_data){
				query	= $.extend({}, wpjam_page_setting.query_data, query);
			}

			if(query.hasOwnProperty('paged') && query.paged <= 1){
				delete query.paged;
			}

			query	= $.wpjam_filter(query);

			if(query){
				$.each(query, function(k, v){ params.set(k, v); });
			}

			params	= params.toString();

			return parts[0]+(params ? '?'+params : '');
		},

		wpjam_push_state: function(){
			let admin_url	= $.wpjam_admin_url();

			if(window.location.href != admin_url || (wpjam_list_table && !wpjam_list_table.loaded)){
				if(wpjam_page_setting.query_data){
					wpjam_params	= $.extend({}, wpjam_page_setting.query_data, wpjam_params);
				}

				window.history.pushState({wpjam_params: wpjam_params}, null, admin_url);
			}
		},

		wpjam_delegate_events: function(selector, sub_selector){
			sub_selector	= sub_selector || '';

			$.each($._data($(selector).get(0), 'events'), function(type, events){
				$.each(events, function(i, event){
					if(event){
						if(event.selector){
							if(!sub_selector || event.selector == sub_selector){
								$('body').on(type, selector+' '+event.selector, event.handler);
								$(selector).off(type, event.selector, event.handler);
							}
						}else{
							$('body').on(type, selector, event.handler);
							$(selector).off(type, event.handler);
						}
					}
				});
			});
		}
	});

	let wpjam_list_table	= wpjam_page_setting.list_table;
	let wpjam_params		= wpjam_page_setting.params;
	let screen_base			= wpjam_page_setting.screen_base;

	$('body .chart').each(function(){
		let options	= $(this).data('options');
		let id		= $(this).prop('id');

		if(options && id){
			options.element	= id;

			let type	= $(this).data('type');

			if(type == 'Line'){
				Morris.Line(options);
			}else if(type == 'Bar'){
				Morris.Bar(options);
			}else if(type == 'Donut'){
				let size	= 240;

				if($(this).next('table').length){
					size	= $(this).next('table').height();
				}

				if(size > 240){
					size	= 240;
				}else if(size < 180){
					size	= 160;
				}

				$(this).height(size).width(size);

				Morris.Donut(options);
			}
		}
	});

	$('body').on('click', '.show-modal', function(){
		if($(this).data('modal_id')){
			$.wpjam_modal($('#'+$(this).data('modal_id')));
		}
	});

	if($('#notice_modal').length){
		$.wpjam_modal($('#notice_modal'));
	}

	$('body').on('tb_unload', '#TB_window', function(){
		if($('#notice_modal').find('.delete-notice').length){
			$('#notice_modal').find('.delete-notice').trigger('click');
		}

		if($(this).hasClass('abscenter')){
			[$.wpjam_position, window.tb_position]	= [window.tb_position, $.wpjam_position];
		}

		$('body #tb_modal').remove();

		if(wpjam_params.page_action){
			delete wpjam_params.page_action;
			delete wpjam_params.data;

			$.wpjam_push_state();
		}else if(wpjam_params.list_action && wpjam_list_table){
			delete wpjam_params.list_action;
			delete wpjam_params.id;
			delete wpjam_params.data;

			$.wpjam_push_state();
		}
	});

	$(window).on('resize', function(){
		if($('#TB_window').hasClass('abscenter')){
			tb_position();
		}
	});

	$('body').on('click', '.is-dismissible .notice-dismiss', function(){
		if($(this).prev('.delete-notice').length){
			$(this).prev('.delete-notice').trigger('click');
		}
	});

	// From mdn: On Mac, elements that aren't text input elements tend not to get focus assigned to them.
	$('body').on('click', 'input[type=submit]', function(e){
		if(!$(document.activeElement).attr('id')){
			$(this).focus();
		}
	});

	window.onpopstate = function(event){
		if(event.state && event.state.wpjam_params){
			wpjam_params	= event.state.wpjam_params;

			if(wpjam_params.page_action){
				$.wpjam_page_action($.extend({}, wpjam_params, {action_type: 'form'}));
			}else if(wpjam_params.list_action && wpjam_list_table){
				$.wpjam_list_table_action($.extend({}, wpjam_params, {action_type: 'form'}));
			}else{
				tb_remove();

				if(wpjam_list_table){
					$.wpjam_list_table_query_items();
				}
			}
		}
	};

	if(wpjam_list_table){
		$.wpjam_list_table_loaded();

		$('body').on('list_table_action_success', function(e, response){
			if(response.action_type != 'form'){
				$.wpjam_list_table_loaded();
			}
		});

		$('body').on('submit', '#list_table_action_form', function(e){
			e.preventDefault();

			if($(this).data('next')){
				window.action_flows = window.action_flows || [];
				window.action_flows.push($(this).data('action'));
			}

			let submit_button	= $(document.activeElement);

			if($(document.activeElement).prop('type') != 'submit'){
				submit_button	= $(this).find(':submit').first();
				submit_button.focus();
			}

			let ids		= $(this).data('ids');
			let args	= {
				action_type :	'submit',
				bulk : 			$(this).data('bulk'),
				list_action :	$(this).data('action'),
				submit_name :	submit_button.attr('name'),
				id :			$(this).data('id'),
				data : 			$(this).serialize(),
				defaults :		$(this).data('data'),
				_ajax_nonce :	$(this).data('nonce')
			};

			if(args.bulk == 2){
				tb_remove();
				$.wpjam_list_table_bulk_action(ids, args);
			}else{
				args.ids	= ids;
				$.wpjam_list_table_action(args);
			}
		});

		$('body').on('submit', 'div.list-table form', function(e){
			let active_element_id	= $(document.activeElement).attr('id');

			if(active_element_id == 'doaction' || active_element_id == 'doaction2'){
				let bulk_name	= $('#'+active_element_id).prev('select').val();

				if(bulk_name == '-1'){
					alert('请选择要进行的批量操作！');
					return false;
				}

				let ids	= $.map($('tbody .check-column input[type="checkbox"]:checked'), (cb) => cb.value);

				if(ids.length == 0){
					alert('请至少选择一项！');
					return false;
				}

				let bulk_actions	= wpjam_list_table.bulk_actions;

				if(bulk_actions && bulk_actions[bulk_name]){
					let bulk_action	= bulk_actions[bulk_name];

					if(bulk_action.confirm && confirm('确定要'+bulk_action.title+'吗?') == false){
						return false;
					}

					let args	= {
						list_action:	bulk_name,
						action_type:	bulk_action.direct ? 'direct' : 'form',
						data:			bulk_action.data,
						_ajax_nonce: 	bulk_action.nonce,
						bulk: 			bulk_action.bulk
					};

					if(args.action_type != 'form' && args.bulk == 2){
						$.wpjam_list_table_bulk_action(ids, args);
					}else{
						args.ids	= ids;
						args.bulk	= 1;

						$.wpjam_list_table_action(args);
					}

					return false;
				}
			}else if(wpjam_list_table.ajax){
				let search_input_id	= $('div.list-table form input[type=search]').attr('id');

				if(active_element_id == 'current-page-selector'){
					let paged	= parseInt($('#current-page-selector').val());
					let total	= parseInt($('#current-page-selector').next('span').find('span.total-pages').text());

					if(paged < 1 || paged > total){
						alert(paged < 1 ? '页面数字不能小于为1' : '页面数字不能大于'+total);

						return false
					}

					wpjam_params.paged	= paged;

					return $.wpjam_list_table_query_items();

				}else if(active_element_id == 'search-submit' || active_element_id == search_input_id){
					wpjam_params	= {s:$('#'+search_input_id).val()};

					return $.wpjam_list_table_query_items();
				}else if(active_element_id == 'filter_action' || active_element_id == 'post-query-submit'){
					wpjam_params	= {};

					$.each($(this).serializeArray(), function(index, param){
						if($.inArray(param.name, ['page', 'tab', 's', 'paged', '_wp_http_referer', '_wpnonce', 'action', 'action2']) == -1){
							wpjam_params[param.name]	= param.value;
						}
					});

					return $.wpjam_list_table_query_items();
				}
			}
		});

		$('body').on('click', '.list-table-action', function(){
			if($(this).data('confirm') && confirm('确定要'+$(this).attr('title')+'吗?') == false){
				return false;
			}

			let args	= {
				action_type :	$(this).data('direct') ? 'direct' : 'form',
				list_action :	$(this).data('action'),
				id : 			$(this).data('id'),
				data : 			$(this).data('data'),
				_ajax_nonce :	$(this).data('nonce')
			};

			let $item	= $.wpjam_list_table_item(args.id);

			if(args.list_action == 'up' || args.list_action == 'down'){
				let action	= args.list_action == 'up' ? 'prev' : 'next';
				let key		= action == 'next' ? 'prev' : 'next';
				args[key]	= $item[action]().find('.ui-sortable-handle').data('id');

				if(!args[key]){
					alert(action == 'next' ? '已经最后一个了，不可下移了。' : '已经是第一个了，不可上移了。');
					return false;
				}

				args.data	= args.data ? args.data + '&'+key+'='+args[key] : key+'='+args[key];
			}else if(args.action_type == 'form'){
				wpjam_params.list_action	= args.list_action;

				if(args.list_action != 'add' && args.id){
					wpjam_params.id	= args.id;
				}

				if(args.data){
					wpjam_params.data	= args.data;
				}
			}

			$.wpjam_list_table_action(args);

			$(this).blur();
		});

		$('body').on('click', '.list-table-filter', function(){
			wpjam_params	= $(this).data('filter');

			return $.wpjam_list_table_query_items();
		});

		$('body').on('click', 'div#col-left .left-item', function(){
			$(this).siblings('.left-item').removeClass('left-current').end().addClass('left-current');

			return $.wpjam_list_table_query_items();
		});

		if(wpjam_list_table.ajax){
			$('body').on('click', 'div.list-table form .pagination-links a', function(){
				wpjam_params.paged	= (new URL($(this).prop('href'))).searchParams.get('paged');

				return $.wpjam_list_table_query_items();
			});

			$('body').on('click', 'div.list-table form th.sorted a, div.list-table form th.sortable a', function(){
				let href = new URL($(this).prop('href'));

				wpjam_params.orderby	= href.searchParams.get('orderby') || $(this).parent().attr('id');
				wpjam_params.order		= href.searchParams.get('order') || ($(this).parent().hasClass('asc') ? 'desc' : 'asc');
				wpjam_params.paged		= 1;

				return $.wpjam_list_table_query_items();
			});
		}

		$('body').on('click', '#col-left .left-pagination-links a', function(){
			let paged	= $(this).hasClass('goto') ? parseInt($(this).prev('input').val()) : $(this).data('left_paged');
			let total	= $(this).parents('.left-pagination-links').find('span.total-pages').text();

			if(paged < 1 || paged > total){
				alert(paged < 1 ? '页面数字不能小于为1' : '页面数字不能大于'+total);

				return false
			}

			wpjam_params.left_paged	= paged;

			return $.wpjam_list_table_query_items('left');
		});

		$('body').on('change', '#col-left select.left-filter', function(){
			let name = $(this).prop('name');

			wpjam_params.left_paged	= 1;
			wpjam_params[name]		= $(this).val();

			return $.wpjam_list_table_query_items('left');
		});

		$('body').on('keyup', '#left-current-page-selector', function(e) {
			if(e.key === 'Enter' || e.keyCode === 13){
				$(this).next('a').trigger('click');
			}
		});
	}

	window.history.replaceState({wpjam_params: wpjam_params}, null);

	if(wpjam_params.page_action){
		$.wpjam_page_action($.extend({}, wpjam_params, {action_type: 'form'}));
	}else if(wpjam_params.list_action && wpjam_list_table){
		$.wpjam_list_table_action($.extend({}, wpjam_params, {action_type: 'form'}));
	}

	$('body').on('click', '.wpjam-button', function(e){
		e.preventDefault();

		if($(this).data('confirm') && confirm('确定要'+$(this).data('title')+'吗?') == false){
			return false;
		}

		let args	= {
			action_type:	$(this).data('direct') ? 'direct' : 'form',
			data:			$(this).data('data'),
			form_data:		$(this).parents('form').serialize(),
			page_action:	$(this).data('action'),
			page_title:		$(this).data('title'),
			_ajax_nonce:	$(this).data('nonce')
		};

		if(args.action_type == 'form'){
			wpjam_params.page_action	= args.page_action;

			if(args.data){
				wpjam_params.data	= args.data;
			}
		}

		return $.wpjam_page_action(args);
	});

	$('body').on('submit', '#wpjam_form', function(e){
		e.preventDefault();

		let submit_button	= $(document.activeElement);

		if($(document.activeElement).prop('type') != 'submit'){
			submit_button	= $(this).find(':submit').first();
			submit_button.focus();
		}

		return $.wpjam_page_action({
			action_type:	'submit',
			data: 			$(this).serialize(),
			page_action:	$(this).data('action'),
			submit_name:	submit_button.attr('name'),
			page_title:		submit_button.attr('value'),
			_ajax_nonce:	$(this).data('nonce')
		});
	});

	$('body').on('submit', '#wpjam_option', function(e){
		e.preventDefault();

		let option_action	= $(document.activeElement).data('action');

		if(option_action == 'reset'){
			if(confirm('确定要重置吗?') == false){
				return false;
			}
		}

		$.wpjam_option_action({
			option_action:	option_action,
			_ajax_nonce: 	$(this).data('nonce'),
			data:			$(this).serialize()
		});
	});
});

