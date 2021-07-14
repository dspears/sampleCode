import React, { useState } from 'react';
import Commit from './CommitComponent';
import  './Commits.css';
import DateRange from '../../DateRange/DateRange';
import DeveloperSelect from '../../DeveloperSelect/DeveloperSelect';
import RepoSelect from '../../RepoSelect/RepoSelect';
import { Icon } from 'react-materialize';

function CommitsPanel(props) {
  const [menuOpen, setMenuOpen] = useState(false);

  return (
    <div className="Commits">
      <h3>COMMITS 
        <span onClick={()=>setMenuOpen(!menuOpen)} className={menuOpen ? 'active' : ''}>
          <Icon>more_vert</Icon>
        </span>
      </h3>
      { menuOpen ? 
        <>
          <DateRange endDate={props.endDate} interval={props.interval} onRangeChange={props.onRangeChange} /> 
          <DeveloperSelect 
            selectedDevs={props.selectedDevs}
            developerInfo={props.developerInfo}
            onSelectDevs={props.onSelectDevs}
          />
          <RepoSelect
            selectedRepos={props.selectedRepos}
            repoInfo={props.repoInfo}
            onSelectRepos={props.onSelectRepos}
          />
        </>
        : ''
      }
      { props.commits && props.commits.length > 0 ?
          props.commits.map(commit => <Commit key={commit.hash} commit={commit}  mouseOver={props.mouseOver} mouseOut={props.mouseOut} />)
        :
          <p>No commits found.</p>
      }
    </div>
  )
}

export default CommitsPanel;
