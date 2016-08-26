<?php

/*
  Document   : AjusteController
  Created on : 11/11/2015, 14:08:37
  Author     : thiago
 */
class AjusteController extends BaseController
{

    public function actionPedido()
    {
        $pessoa = Pessoa::model()->with(array(
            'DadosFuncionais' => array(
                'joinType' => 'inner join',
                'on' => 'coalesce(DadosFuncionais.data_desligamento, DATE_ADD(CURRENT_TIMESTAMP(), INTERVAL 1 DAY)) > CURRENT_TIMESTAMP() 
                        and coalesce(DadosFuncionais.data_aposentadoria, DATE_ADD(CURRENT_TIMESTAMP(), INTERVAL 1 DAY)) > CURRENT_TIMESTAMP() ' .
                (isset($_GET['v']) ? 'and DadosFuncionais.nr_vinculo = ' . intval($_GET['v']) : '')
            ),
            'DadosFuncionais.GrupoEmprego' => array(
                'joinType' => 'inner join',
                'on' => "GrupoEmprego.segmento_grupo = 'T'"
            ),
            'DadosFuncionais.CatFuncional',
            'DadosFuncionais.OrgaoLotacao',
            'DadosFuncionais.OrgaoExercicio',
        ))->findByPk(Yii::app()->user->id_pessoa);

        if ($pessoa) {
            if (count($pessoa->DadosFuncionais) == 1) {
                $pessoa->DadosFuncionais = $pessoa->DadosFuncionais[0];

                if ($pessoa->DadosFuncionais->regime_trabalho == 'DE')
                    $pessoa->DadosFuncionais->regime_trabalho = 40;

                $ajustes = new Ajuste('search');
                $ajustes->id_pessoa = $pessoa->id_pessoa;
                $ajustes->nr_vinculo = $pessoa->DadosFuncionais->nr_vinculo;
                
                $abonos = new Abono('search');
                $abonos->id_pessoa = $pessoa->id_pessoa;
                $abonos->nr_vinculo = $pessoa->DadosFuncionais->nr_vinculo;

                $this->render('pedido', array(
                    'pessoa' => $pessoa,
                    'ajustes' => $ajustes->search(),
                    'abonos' => $abonos->search(),
                    'justificativas' => JustificativaAjuste::model()->findAll(array('order' => 'texto_justificativa')),
                    'registroAjustar' => (isset($_GET['n']) ? Ponto::model()->findByPk($_GET['n']) : NULL),
                ));
            }
            else {
                // mais de um vinculo
                $this->render('pedido', array(
                    'pessoa' => $pessoa,
                ));
            }
        }
        else {
            $this->render('system.cpd.views.mensagem', array('mensagem' => 'O ponto eletrônico não está liberado para o seu vínculo.', 'classe' => 'Info'));
        }
    }

    public function actionEnviarPedido()
    {
        $msg = "";
        $erro = false;
        if (!isset($_POST['tipo']) || !in_array($_POST['tipo'], array('E', 'S', 'P', 'A'))) {
            $msg .= "tipo de ajuste não selecionado. <br/>";
        }
        if (!isset($_POST['data']) || (trim($_POST['data']) == "")) {
            $msg .= "Dia para o ajuste não selecionado. <br/>";
        }
        if (!isset($_POST['hora']) || (trim($_POST['hora']) == "")) {
            $msg .= "Hora para o ajuste não selecionada. <br/>";
        }
        $data = implode("-", array_reverse(explode("/", $_POST['data'])));
        if ($_POST['tipo'] != 'A') {
            $dataCompleta = implode("-", array_reverse(explode("/", $_POST['data']))) . " " . $_POST['hora'];
            if (strtotime($dataCompleta) == 0) {
                $msg .= "Data do ajuste não é uma data válida. <br/>";
            }
        }
        if (!isset($_POST['justificativa']) || (($_POST['justificativa'] == "o") && (trim($_POST['outraJustificativa']) == ""))) {
            $msg .= "justificativa para o ajuste não informada. <br/>";
        }
        if ($msg != "") {
            $erro = true;
        }
        else {
            $tipo = $_POST['tipo'];
            $msg = "Pedido de ajuste enviado com sucesso!";
            $nrVinculo = intval($_POST['nrVinculo']);
            $pessoa = Pessoa::model()->with(array(
                'DadoFuncional' => array(
                    'joinType' => 'inner join',
                    'on' => 'DadoFuncional.nr_vinculo = ' . $nrVinculo,
                )
            ))->findByPk(Yii::app()->user->id_pessoa);

            if ($tipo != 'A') {
                // Se for entrada, saida ou periodo
                $ajuste = new Ajuste();
                $dataHoraPonto = $dataCompleta;
                $ajuste->data_hora_ponto = $dataHoraPonto;
                // se for periodo, o primeiro registro e de entrada
                $ajuste->entrada_saida = ($tipo != 'P' ? $tipo : 'E');
                $ajuste->nr_ponto = $_POST['nrPonto'] ? : NULL;
            }
            else {
                // se for abono
                $ajuste = new Abono();
                $dataAbono = $data;
                $ajuste->data_abono = $dataAbono;
                // periodo em minutos
                $aux = explode(":", $_POST['hora']);
                $periodoAbono = $aux[0]*60 + $aux[1];
                $ajuste->periodo_abono = $periodoAbono;
            }
            $ajuste->id_pessoa = $pessoa->id_pessoa;
            $ajuste->matricula = $pessoa->DadoFuncional->matricula;
            $ajuste->nr_vinculo = $pessoa->DadoFuncional->nr_vinculo;
            $ajuste->id_pessoa_registro = $pessoa->id_pessoa;
            $ajuste->data_hora_registro = new CDbExpression("CURRENT_TIMESTAMP()");
            $ajuste->ip_registro = $_SERVER['REMOTE_ADDR'];
            $ajuste->justificativa = ($_POST['justificativa'] == 'o' ? utf8_decode($_POST['outraJustificativa']) : NULL);
            $ajuste->nr_justificativa = ($_POST['justificativa'] != 'o' ? $_POST['justificativa'] : NULL);

            $transacao = Yii::app()->db->beginTransaction();
            try {
                if ($ajuste->save()) {
                    if (!empty($_FILES['anexos']['name']) && (trim($_FILES['anexos']['name'][0]) != '')) {
                        for ($i = 0; $i < count($_FILES['anexos']['name']); $i++) {
                            if (!$this->fazUploadArquivo($_FILES['anexos'], $i, $ajuste, $tipo)) {
                                $transacao->rollback();
                                $erro = true;
                                $msg = "Ocorreu um erro ao salvar os anexos do pedido.";
                                break;
                            }
                        }
                    }
                    if ($tipo == 'P') {
                        // se for periodo, faz um novo registro de saida
                        $ajusteSaida = new Ajuste();
                        $dataHoraPonto = implode("-", array_reverse(explode("/", $_POST['data']))) . " " . $_POST['horaSaida'];
                        $ajusteSaida->data_hora_ponto = $dataHoraPonto;
                        // se for periodo, o primeiro registro e de entrada
                        $ajusteSaida->entrada_saida = 'S';
                        $ajusteSaida->id_pessoa = $pessoa->id_pessoa;
                        $ajusteSaida->matricula = $pessoa->DadoFuncional->matricula;
                        $ajusteSaida->nr_vinculo = $pessoa->DadoFuncional->nr_vinculo;
                        $ajusteSaida->id_pessoa_registro = $pessoa->id_pessoa;
                        $ajusteSaida->data_hora_registro = new CDbExpression("CURRENT_TIMESTAMP()");
                        $ajusteSaida->ip_registro = $_SERVER['REMOTE_ADDR'];
                        $ajusteSaida->justificativa = ($_POST['justificativa'] == 'o' ? utf8_decode($_POST['outraJustificativa']) : NULL);
                        $ajusteSaida->nr_justificativa = ($_POST['justificativa'] != 'o' ? $_POST['justificativa'] : NULL);
                        if ($ajusteSaida->save()) {
                            // se teve anexos, salva os mesmos arquivos para a saida
                            if (!empty($_FILES['anexos']['name']) && (trim($_FILES['anexos']['name'][0]) != '')) {
                                $arquivos = ArquivoAjuste::model()->findAllByAttributes(array('nr_ajuste' => $ajuste->nr_ajuste));
                                foreach ($arquivos as $arquivo) {
                                    $novoArquivo = new ArquivoAjuste();
                                    // todos atributos sao iguais, exceto o nr_ajuste, que vai apontar para o registro de saida
                                    $novoArquivo->nr_ajuste = $ajusteSaida->nr_ajuste;
                                    $novoArquivo->cod_repositorio = $arquivo->cod_repositorio;
                                    $novoArquivo->descricao_arquivo = $arquivo->descricao_arquivo;
                                    $novoArquivo->save();
                                }
                            }
                        }
                        else {
                            $erro = true;
                            $msg = "Ocorreu um erro ao salvar a hora de saída. ".print_r($ajusteSaida->getErrors(), true);
                        }
                    }
                    if (!$erro) {
                        // TODO procedimento para avisar a chefia
                        $transacao->commit();
                    }
                }
                else {
                    $transacao->rollback();
                    $erro = true;
                    $msg = "Ocorreu um erro ao salvar o ajuste. " . print_r($ajuste->getErrors(), true);
                }
            }
            catch (Exception $e) {
                $transacao->rollback();
                $erro = true;
                $msg = "Ocorreu um erro ao salvar o ajuste. " . $e->getMessage();
            }
        }

        print CJSON::encode(array(
                'erro' => $erro,
                'mensagem' => $msg
        ));
    }

    public function actionExcluirPedido() 
    {
        if (isset($_POST['nr'], $_POST['tipo']) && in_array($_POST['tipo'], array('ajuste', 'abono'))) { 
            if ($_POST['tipo'] == 'ajuste') {
                $pedido = Ajuste::model()->findByPk($_POST['nr']);
                $chave = 'nr_ajuste';
            }
            else {
                $pedido = Abono::model()->findByPk($_POST['nr']);
                $chave = 'nr_abono';
            }
        
            if (($pedido->id_pessoa == Yii::app()->session['id_pessoa']) && (trim($pedido->indicador_certificado) == "")) {
                $pedido->indicador_excluido = 'S';
                $pedido->id_pessoa_registro = Yii::app()->session['id_pessoa'];
                $pedido->data_hora_registro = new CDbExpression('CURRENT_TIMESTAMP()');
                if ($pedido->save('indicador_excluido, id_pessoa_registro, data_hora_registro')) {
                    print 'Pedido excluído com sucesso!';
                }
                else {
                    print 'Ocorreu um erro ao excluir o pedido.'.print_r($pedido->getErrors(), true);
                }
            }
            else {
                print 'Você não pode excluir esse pedido';
            }
        }
    }
    
    public function actionPedidosAvaliacao()
    {
        $orgaosChefiados = RestricaoRelogio::getOrgaosChefia(Yii::app()->session['id_pessoa']);
        if (!empty($orgaosChefiados)) {
            $criteria = new CDbCriteria();
            $criteria->with = array(
                'Pessoa' => array(
                    'select' => 'nome_pessoa'
                ),
                'DadoFuncional' => array(
                    'select' => 'regime_trabalho',
                ),
                'DadoFuncional.CatFuncional' => array(
                    'select' => 'nome_categoria'
                ),
                'DadoFuncional.OrgaoExercicio' => array(
                    'select' => 'sigla_orgao, nome_orgao'
                ), 'JustificativaAjuste', 'Arquivos', 'Certificador'
            );
            $criteria->condition = "
                t.data_hora_certificacao is null 
                and coalesce(t.indicador_excluido, 'N') = 'N'
                and t.id_pessoa <> :CodPessoa1
                and DadoFuncional.orgao_exercicio in (
                    select id_orgao from fn_hierarquia_orgao_funcoes_pessoa (:id_pessoa2) 
                    union
                    select id_orgao from fn_permissoes (:id_pessoa3, 'RH', 'rh702',null) 
                    union
                    select id_orgao 
                    from TABELAS_AUXILIARES..ADOrgaoDirigenteExercicio TAUX
                        inner join SERVIDOR S on S.matricula = TAUX.matricula  
                    where S.id_pessoa = :id_pessoa4 
                )";
            $criteria->params = array(
                ':CodPessoa1' => Yii::app()->user->id_pessoa,
                ':id_pessoa2' => Yii::app()->user->id_pessoa,
                ':id_pessoa3' => Yii::app()->user->id_pessoa,
                ':id_pessoa4' => Yii::app()->user->id_pessoa,
            );

            $this->render('pedidosAvaliacao', array(
                'ajustes' => new CActiveDataProvider('Ajuste', array(
                    'criteria' => $criteria,
                    'sort' => array(
                        'defaultOrder' => array(
                            'data_hora_registro' => CSort::SORT_ASC,
                        ),
                    ),
                )),
                'abonos' => new CActiveDataProvider('Abono', array(
                    'criteria' => $criteria,
                    'sort' => array(
                        'defaultOrder' => array(
                            'data_hora_registro' => CSort::SORT_ASC,
                        ),
                    ),
                )),
            ));
        }
        else {
            // nao e chefe
            $this->render('system.cpd.views.mensagem', array('mensagem' => 'Você não possui cargo de chefia.', 'classe' => 'Info'));
        }
    }
    
    public function actionPedidosCertificados()
    {
        $orgaosChefiados = RestricaoRelogio::getOrgaosChefia(Yii::app()->session['id_pessoa']);
        if (!empty($orgaosChefiados)) {
            $criteria = new CDbCriteria();
            $criteria->with = array(
                'Pessoa' => array(
                    'select' => 'nome_pessoa'
                ),
                'DadoFuncional' => array(
                    'select' => 'regime_trabalho',
                ),
                'DadoFuncional.CatFuncional' => array(
                    'select' => 'nome_categoria'
                ),
                'DadoFuncional.OrgaoExercicio' => array(
                    'select' => 'sigla_orgao, nome_orgao'
                ), 'JustificativaAjuste', 'Arquivos', 'Certificador'
            );
            $criteria->condition = "
                t.data_hora_certificacao is not null 
                and coalesce(t.indicador_excluido, 'N') = 'N'
                and t.id_pessoa <> :CodPessoa1
                and DadoFuncional.orgao_exercicio in (
                    select id_orgao from fn_hierarquia_orgao_funcoes_pessoa (:id_pessoa2) 
                    union
                    select id_orgao from fn_permissoes (:id_pessoa3, 'RH', 'rh702',null) 
                    union
                    select id_orgao 
                    from TABELAS_AUXILIARES..ADOrgaoDirigenteExercicio TAUX
                        inner join SERVIDOR S on S.matricula = TAUX.matricula  
                    where S.id_pessoa = :id_pessoa4 
                )";
            $criteria->params = array(
                ':CodPessoa1' => Yii::app()->user->id_pessoa,
                ':id_pessoa2' => Yii::app()->user->id_pessoa,
                ':id_pessoa3' => Yii::app()->user->id_pessoa,
                ':id_pessoa4' => Yii::app()->user->id_pessoa,
            );

            $this->render('pedidosCertificados', array(
                'ajustes' => new CActiveDataProvider('Ajuste', array(
                    'criteria' => $criteria,
                    'sort' => array(
                        'defaultOrder' => array(
                            'data_hora_registro' => CSort::SORT_DESC,
                        ),
                    ),
                )),
                'abonos' => new CActiveDataProvider('Abono', array(
                    'criteria' => $criteria,
                    'sort' => array(
                        'defaultOrder' => array(
                            'data_hora_registro' => CSort::SORT_DESC,
                        ),
                    ),
                )),
            ));
        }
        else {
            // nao e chefe
            $this->render('system.cpd.views.mensagem', array('mensagem' => 'Você não possui cargo de chefia.', 'classe' => 'Info'));
        }
    }

    public function actionDadosPedido()
    {
        $nrAjuste = $_POST['nr'];
        if ($_POST['tipo'] == 'ajuste') {
            $pedido = Ajuste::model()->with('Pessoa', 'Ponto', 'JustificativaAjuste', 'Arquivos', 'Certificador')->findByPk($nrAjuste);
        }
        else {
            $pedido = Abono::model()->with('Pessoa', 'JustificativaAjuste', 'Arquivos', 'Certificador')->findByPk($nrAjuste);
        }
        $registrosDoDia = PontoEAjuste::model()->findAll(array(
            'condition' => "id_pessoa = :id_pessoa
                and nr_vinculo = :nr_vinculo
                and DATE_FORMAT(data_hora_ponto, '%d/%m/%Y') = :Dia
                and (
                    (tipo = 'A' and indicador_certificado = 'S') 
                    or 
                    (tipo = 'R' and not exists (
                        select 1 from ajuste A
                        where A.nr_ponto = nr_seq
                    )))",
            'params' => array(
                ':id_pessoa' => $pedido->id_pessoa,
                ':nr_vinculo' => $pedido->nr_vinculo,
                ':Dia' => date('d/m/Y', strtotime($_POST['tipo'] == 'ajuste' ? $pedido->data_hora_ponto : $pedido->data_abono)),
            ),
            'order' => 'data_hora_ponto'
        ));
        print $this->renderPartial('dadosPedido', array('pedido' => $pedido, 'tipo' => ucwords($_POST['tipo']), 'registrosDoDia' => $registrosDoDia), true);
    }

    public function actionDadosPedidoCertificado()
    {
        $nrAjuste = $_POST['nr'];
        if ($_POST['tipo'] == 'ajuste') {
            $pedido = Ajuste::model()->with('Pessoa', 'Ponto', 'JustificativaAjuste', 'Arquivos', 'Certificador')->findByPk($nrAjuste);
        }
        else {
            $pedido = Abono::model()->with('Pessoa', 'JustificativaAjuste', 'Arquivos', 'Certificador')->findByPk($nrAjuste);
        }
        print $this->renderPartial('dadosPedidoCertificado', array('pedido' => $pedido, 'tipo' => ucwords($_POST['tipo'])), true);
    }
    
    public function actionCertificaPedido()
    {
        if (isset($_POST['nrPedido']) && is_numeric($_POST['nrPedido']) && in_array($_POST['certifica'], array('S', 'N'))) {
            $erro = false;
            $msg = "Pedido ".($_POST['certifica'] == 'N' ? 'não' : '')." indicador_certificado com sucesso!";
            $tipo = $_POST['tipo'];
            $criteria = array(
                'condition' => "
                    t.data_hora_certificacao is null 
                    and t.id_pessoa <> :CodPessoa1
                    and DadoFuncional.orgao_exercicio in (
                        select id_orgao from fn_hierarquia_orgao_funcoes_pessoa (:id_pessoa2) 
                        union
                        select id_orgao from fn_permissoes (:id_pessoa3, 'RH', 'rh702',null) 
                        union
                        select id_orgao 
                        from TABELAS_AUXILIARES..ADOrgaoDirigenteExercicio TAUX
                            inner join SERVIDOR S on S.matricula = TAUX.matricula  
                        where S.id_pessoa = :id_pessoa4 
                    )",
                'params' => array(
                    ':CodPessoa1' => Yii::app()->user->id_pessoa,
                    ':id_pessoa2' => Yii::app()->user->id_pessoa,
                    ':id_pessoa3' => Yii::app()->user->id_pessoa,
                    ':id_pessoa4' => Yii::app()->user->id_pessoa,
                )
            );
            
            if ($tipo == 'ajuste') {
                $pedido = Ajuste::model()->with('DadoFuncional')->findByPk($_POST['nrPedido'], $criteria);
            }
            else {
                $pedido = Abono::model()->with('DadoFuncional')->findByPk($_POST['nrPedido'], $criteria);               
            }
            
            if ($pedido) {
                $pedido->justificativa_certificacao = utf8_decode($_POST['justificativa']);
                $pedido->indicador_certificado = $_POST['certifica'];
                $pedido->id_pessoa_certificacao = Yii::app()->user->id_pessoa;
                $pedido->data_hora_certificacao = new CDbExpression("CURRENT_TIMESTAMP()");
                
                if ($pedido->save(true, array('indicador_certificado', 'id_pessoa_certificacao', 'data_hora_certificacao', 'justificativa_certificacao'))) {
                    $dataPedido = ($tipo == 'ajuste' ? $pedido->data_hora_ponto : $pedido->data_abono);
                    // se a certificacao esta acontecendo apos o fechamento do mes do pedido, recalcula o total de horas
                    if (($pedido->indicador_certificado == 'S') && 
                        ((date('m') > date('m', strtotime($dataPedido))) || (date('Y') > date('Y', strtotime($dataPedido))))) {
                        $mesAnterior = (date('m') != 1 ? date('m')-1 : 12);
                        $anoAnterior = (date('m') != 1 ? date('Y') : date('Y')-1);
                        CargaHorariaMesServidor::buscaDadosESalva($pedido->matricula, $pedido->nr_vinculo, $mesAnterior, $anoAnterior);
                    }
                }
                else {
                    $erro = false;
                    $msg = "Ocorreu um erro ao certificar o pedido.".print_r($pedido->getErrors(), true);
                }
            }
            else {
                $erro = true;
                $msg = "Pedido não encontrado ou não autorizado.";
            }
        }
        else {
            $erro = true;
            $msg = "Erro na passagem de parâmetros.";
        }
        
        print CJSON::encode(array(
            'erro' => $erro,
            'mensagem' => $msg
        ));
    }

    public function actionCertificaVarios()
    {
        $erro = false;
        $msg = "Pedido(s) indicador_certificado(s) com sucesso!";
        if (isset($_POST['pedidos']) && is_array($_POST['pedidos']) && in_array($_POST['tipo'], array('Ajuste', 'Abono'))) {
            $tipo = $_POST['tipo'];
            $criteria = array(
                'condition' => "
                    t.data_hora_certificacao is null 
                    and t.id_pessoa <> :CodPessoa1
                    and DadoFuncional.orgao_exercicio in (
                        select id_orgao from fn_hierarquia_orgao_funcoes_pessoa (:id_pessoa2) 
                        union
                        select id_orgao from fn_permissoes (:id_pessoa3, 'RH', 'rh702',null) 
                        union
                        select id_orgao 
                        from TABELAS_AUXILIARES..ADOrgaoDirigenteExercicio TAUX
                            inner join SERVIDOR S on S.matricula = TAUX.matricula  
                        where S.id_pessoa = :id_pessoa4 
                    )
                    and t.nr_seq$tipo in (".str_replace("'", "''", implode(",", $_POST['pedidos'])).")",
                'params' => array(
                    ':CodPessoa1' => Yii::app()->user->id_pessoa,
                    ':id_pessoa2' => Yii::app()->user->id_pessoa,
                    ':id_pessoa3' => Yii::app()->user->id_pessoa,
                    ':id_pessoa4' => Yii::app()->user->id_pessoa,
                )
            );
            if ($tipo == 'Ajuste') {
                $pedidos = Ajuste::model()->with('DadoFuncional')->findAll($criteria);
            }
            else {
                $pedidos = Abono::model()->with('DadoFuncional')->findAll($criteria);
            }
            
            if (!empty($pedidos)) {
                foreach($pedidos as $pedido) {
                    $pedido->indicador_certificado = 'S';
                    $pedido->id_pessoa_certificacao = Yii::app()->user->id_pessoa;
                    $pedido->data_hora_certificacao = new CDbExpression("CURRENT_TIMESTAMP()");

                    if ($pedido->save(true, array('indicador_certificado', 'id_pessoa_certificacao', 'data_hora_certificacao'))) {
                        // se a certificacao esta acontecendo apos o fechamento do mes do pedido, recalcula o total de horas
                        if (date('m') > date('m', strtotime(($tipo == 'Ajuste' ? $pedido->data_hora_ponto : $pedido->data_abono)))) {
                            $mesAnterior = (date('m') != 1 ? date('m')-1 : 12);
                            $anoAnterior = (date('m') != 1 ? date('Y') : date('Y')-1);
                            CargaHorariaMesServidor::buscaDadosESalva($pedido->matricula, $pedido->nr_vinculo, $mesAnterior, $anoAnterior);
                        }
                    }
                    else {
                        $erro = false;
                        $msg = "Ocorreu um erro ao certificar o pedido.".print_r($pedido->getErrors(), true);
                        break;
                    }
                }
            }
            else {
                $erro = true;
                $msg = "Pedido não encontrado ou não autorizado.";
            }
        }
        else {
            $erro = true;
            $msg = "Erro na passagem de parâmetros.";
        }
        
        print CJSON::encode(array(
            'erro' => $erro,
            'mensagem' => $msg
        ));
    }
    
    private function fazUploadArquivo($arquivos, $i, $ajuste, $tipo)
    {
        $repositorio = new Repositorio;
        $identificadorRepositorio = $repositorio->upload(92, $arquivos["name"][$i], file_get_contents($arquivos["tmp_name"][$i]), $arquivos["type"][$i]);
        if ($identificadorRepositorio != '') {
            $anexo = new ArquivoAjuste();
            if ($tipo != 'A') {
                $anexo->nr_ajuste = $ajuste->nr_ajuste;
            }
            else {
                $anexo->nr_abono = $ajuste->nr_abono;
            }
            $anexo->descricao_arquivo = utf8_decode($arquivos['name'][$i]);
            $anexo->cod_repositorio = $identificadorRepositorio;
            
            return $anexo->save();
        }
        else {
            return false;
        }
    }

}