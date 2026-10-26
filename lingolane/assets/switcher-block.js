/* Developed by @tannguyen. */
(function (wp) {
    'use strict';
    var el = wp.element.createElement;
    var __ = wp.i18n.__;
    var ServerSideRender = wp.serverSideRender.default || wp.serverSideRender;

    wp.blocks.registerBlockType('lingolane/language-switcher', {
        apiVersion: 2,
        title: __('Language Switcher', 'lingolane'),
        description: __('Switch between available translations.', 'lingolane'),
        icon: 'translation',
        category: 'widgets',
        attributes: {
            format: { type: 'string', default: 'both' },
            flagShape: { type: 'string', default: 'rect' },
            showCurrent: { type: 'boolean', default: true }
        },
        supports: { html: false },
        edit: function (props) {
            var blockProps = wp.blockEditor.useBlockProps();
            return el(wp.element.Fragment, null,
                el(wp.blockEditor.InspectorControls, null,
                    el(wp.components.PanelBody, { title: __('Language Switcher', 'lingolane') },
                        el(wp.components.SelectControl, {
                            label: __('Display', 'lingolane'),
                            value: props.attributes.format,
                            options: [
                                { label: __('Flags and names', 'lingolane'), value: 'both' },
                                { label: __('Flags', 'lingolane'), value: 'flags' },
                                { label: __('Names', 'lingolane'), value: 'names' }
                            ],
                            onChange: function (value) { props.setAttributes({ format: value }); }
                        }),
                        el(wp.components.SelectControl, {
                            label: __('Flag shape', 'lingolane'),
                            value: props.attributes.flagShape,
                            options: [
                                { label: __('Rectangle', 'lingolane'), value: 'rect' },
                                { label: __('Round', 'lingolane'), value: 'round' },
                                { label: __('Square', 'lingolane'), value: 'square' }
                            ],
                            onChange: function (value) { props.setAttributes({ flagShape: value }); }
                        }),
                        el(wp.components.ToggleControl, {
                            label: __('Show current language', 'lingolane'),
                            checked: props.attributes.showCurrent,
                            onChange: function (value) { props.setAttributes({ showCurrent: value }); }
                        })
                    )
                ),
                el('div', blockProps,
                    el(ServerSideRender, { block: 'lingolane/language-switcher', attributes: props.attributes })
                )
            );
        },
        save: function () { return null; }
    });
}(window.wp));
