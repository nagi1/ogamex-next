/**
 * Screen-area vocabularies for the legacy CSS splitter.
 *
 * Each vocabulary is an ordered list of `[area, pattern]` pairs, most specific
 * first. A rule belongs to the first area whose pattern matches one of its
 * selectors, so `base` claims the element reset before any screen pattern can,
 * and a page never swallows the components inside it. Anything unmatched falls
 * back to `common`, which is the leftover bucket and never names a file.
 */

const ingame = [
  ["base", /^(html|body|p|h[1-6]|a|ul|ol|li|dl|dt|dd|table|caption|th|td|em|strong|small|blockquote|pre|fieldset|legend|label|img|hr)\b|^:focus|^:active/i],
  ["scrollbar", /(mcsb_|mcs-3d|scrollbar|scrolltools|nicescroll)/i],
  ["jquery-ui", /^\.ui-|jquery/i],
  ["slider", /anythingslider/i],
  ["lifeforms", /(lifeform|characterclass|graveyard|exodus|rewarding|lfbuildings|lfresearch)/i],
  ["tutorial", /(#tutorial|tutorial)/i],
  ["alliance", /(alliance|buddy|diplomac|union)/i],
  ["marketplace", /(marketplace|trader|buyresource|itemactivation|#shop|#merchant)/i],
  ["combat", /(combatsim|combat|battle|simulat|honorable|dishonorable)/i],
  ["messages", /(message|bbcode|#chat|chat_bar|msg_)/i],
  ["galaxy", /(galaxy|spaceobject|debris|planetdata|\bmoon\b)/i],
  ["fleet", /(fleet|shipyard|jumpgate|phalanx|missile|recycle|espionage|expedition|ship_list|shipimage)/i],
  ["research", /(research|techtree|technology)/i],
  ["resources", /(resource|production|energy|metal|crystal|deuterium|storage|supply)/i],
  ["buildings", /(building|construction|#buttonz)/i],
  ["premium", /(premium|officer|buff|happyedit)/i],
  ["events", /(eventbox|countdown|movement|timer|promotioncountdown)/i],
  ["highscore", /(highscore|statistics|ranking)/i],
  ["tables", /(ct_|table|tablesorter|\.odd\b|\.over\b)/i],
  ["tabs", /(tabsbelow|subsection_tabs|tabs_btn|\.tabs\b|subtabs|#tab-)/i],
  ["forms", /(input|textarea|select\b|btn|awesome-button|checkbox|radio|\bform\b|dropdown)/i],
  ["icons", /(icon|sprite|emoji)/i],
  ["overlays", /(overlay|dialog|popup|#popupcontent|federationlayer|detailsopened|detailsclosed|detail_screen)/i],
  ["tooltips", /(tooltip|markitup)/i],
  ["animations", /(keyframes|animation|transition|spinner)/i],
  ["planetbar", /(planetbar|planetnameheader|smallplanet|myplanets|planetlink|#planet\b)/i],
  ["header", /(#info\b|#header|resourcebar|#resources|#topnav|#rightmenu|#leftmenu)/i],
  ["empire", /(#empirecomponent|#repairlayer|#empire|ipitaskitem|\.values\b|#technologies)/i],
  ["boxes", /(box|container|\.content\b|panel)/i],
  ["layout", /(#rechts|#inhalt|#contentwrapper|#pagecontent|#mainmenu|netz|contentz|clearfloat|column|#links|pagination)/i],
  ["toolbox", /(floatleft|floatright|textcenter|hide|clear|nomargin|cursor|no-touch|disabled|\.off\b)/i],
];

const outgame = [
  ["base", /^(html|body|p|h[1-6]|a|ul|ol|li|dl|dt|dd|table|caption|th|td|em|strong|small|blockquote|pre|fieldset|legend|label|img|hr)\b|^:focus|^:active|^::-|^:-/i],
  ["fancybox", /(fancybox|fancy-bg)/i],
  ["validation", /(formerror|invalid|pwdwarning|valid-icon|validchar|nodisplay)/i],
  ["universe-filter", /(characteristic|serverlist|filter|uni_selection|ui-slider|universeDistinction|tooltip_header)/i],
  ["servers", /(#server|server-row|server_table|uni_span|exodus|hoverSelectbox|margin-uni-selection)/i],
  ["dialogs", /(ui-dialog|ui-corner|ui-button|login_dialog|errorpopup)/i],
  ["invite", /(invitetext|inviteserverbox)/i],
  ["login", /(#login|loginbtn|loginsubmit|pwlost|resendlink|passwordlost|generatename|passwordloginformerror|usernameloginformerror)/i],
  ["registration", /(#subscribe|subscribeform|stayloggedin|#agb|agblabel|agecheck|regsubmit|contentwrap)/i],
  ["language", /(#language|langbg|langhead|langfooter|#trigger|#selected|displaylang)/i],
  ["footer", /(#footer|footerlinks|contentfooter|copyright|linksandcopyright|logos|gflogo)/i],
  ["header", /(#header|eventtext|#push|#start \.last|start_rtl2)/i],
  ["landing", /(#start|#parallax|#nebula|#overlay)/i],
  ["content", /(#content|#menu|#tabs|tabcontent|wallpapers|#screens|flashtrailer|trailer)/i],
  ["team", /(team_table|td_team|td\.alt)/i],
  ["social", /(socialmedialogo|twitterlogo|gpluslogo|fblogo|mmonetbar|ipadapp|#usk|#safeplay|dieie6)/i],
  ["forms", /(input|textarea|select\b|checkbox|radio|\bform\b|placeholder|input-wrap)/i],
  ["buttons", /(button|ajaxsubmit|regsubmit)/i],
  ["animations", /(keyframes|animation|transition|spinner)/i],
  ["tables", /(table|\.alt\b|clearfix|clearfloat)/i],
  ["boxes", /(box|wrap|inner|panel)/i],
  ["toolbox", /(hidden|align_center|filter_off|margin-|\.expand\b)/i],
  ["debug", /(debugmode)/i],
];

export const vocabularies = { ingame, outgame };

export function areaVocabulary(name) {
  const vocabulary = vocabularies[name];
  if (!vocabulary) {
    throw new Error(
      "Unknown area vocabulary: " + name + " (expected " + Object.keys(vocabularies).join(" or ") + ")",
    );
  }

  return vocabulary;
}
