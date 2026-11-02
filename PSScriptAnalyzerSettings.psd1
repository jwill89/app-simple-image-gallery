# PSScriptAnalyzer for the scripts in scripts/: its default rules, plus the
# one-true-brace formatting they are written in. CI runs it on every push.
@{
    ExcludeRules = @(
        # The deploy script tells whoever runs it what it is doing. Since
        # PowerShell 5, Write-Host writes to the information stream, which can be
        # captured or silenced, so the rule's reason doesn't hold for a script
        # run by hand.
        'PSAvoidUsingWriteHost',
        # The scripts run under pwsh (deploy.ps1's shebang; it relies on 7.4
        # behaviour), which reads UTF-8 without a BOM. The repo keeps every text
        # file BOM-less UTF-8 (.editorconfig), and the only non-ASCII characters
        # are in comments.
        'PSUseBOMForUnicodeEncodedFile'
    )
    Rules        = @{
        PSPlaceOpenBrace           = @{
            Enable             = $true
            OnSameLine         = $true
            NewLineAfter       = $true
            IgnoreOneLineBlock = $true
        }
        PSPlaceCloseBrace          = @{
            Enable             = $true
            NewLineAfter       = $false
            IgnoreOneLineBlock = $true
            NoEmptyLineBefore  = $false
        }
        PSUseConsistentIndentation = @{
            Enable              = $true
            Kind                = 'space'
            PipelineIndentation = 'IncreaseIndentationForFirstPipeline'
            IndentationSize     = 4
        }
        PSUseConsistentWhitespace  = @{
            Enable                                  = $true
            CheckInnerBrace                         = $true
            CheckOpenBrace                          = $true
            CheckOpenParen                          = $true
            CheckOperator                           = $true
            CheckPipe                               = $true
            CheckPipeForRedundantWhitespace         = $false
            CheckSeparator                          = $true
            CheckParameter                          = $false
            IgnoreAssignmentOperatorInsideHashTable = $true
        }
        PSAlignAssignmentStatement = @{
            Enable         = $true
            CheckHashtable = $true
        }
        PSUseCorrectCasing         = @{
            Enable = $true
        }
    }
}
